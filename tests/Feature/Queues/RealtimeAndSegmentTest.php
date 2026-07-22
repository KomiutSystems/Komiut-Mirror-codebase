<?php

declare(strict_types=1);

namespace Tests\Feature\Queues;

use App\Events\VehicleMoved;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;

/**
 * Realtime driver tracking + the driver manifest + pick-as-you-go seat reuse:
 * - VehicleLocationController (broadcast / nearby)
 * - TripManifestController
 * - SegmentSeatAvailability (segment-overlap seat sharing)
 */
final class RealtimeAndSegmentTest extends QueueTestCase
{
    #[Test]
    public function a_crew_ping_updates_the_live_index_and_broadcasts(): void
    {
        Event::fake([VehicleMoved::class]);
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);

        Sanctum::actingAs($world['owner']); // the vehicle owner crews it

        $this->postJson('/api/auth/book_a_ride/location', [
            'queue_id' => $queue->id,
            'latitude' => -1.2833,
            'longitude' => 36.8167,
        ])->assertStatus(202)->assertJsonPath('status', 'broadcasting');

        $this->assertDatabaseHas('vehicle_locations', [
            'vehicle_id' => $world['vehicle']->id,
            'queue_id' => $queue->id,
            'broadcasting' => true,
        ]);
        Event::assertDispatched(VehicleMoved::class);

        // A second ping east of the first yields a non-zero heading.
        $this->postJson('/api/auth/book_a_ride/location', [
            'queue_id' => $queue->id,
            'latitude' => -1.2833,
            'longitude' => 36.8300,
        ])->assertStatus(202)->assertJsonPath('heading', fn ($h) => $h > 0 && $h < 180);
    }

    #[Test]
    public function a_stranger_cannot_broadcast_for_a_vehicle_they_dont_crew(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);

        Sanctum::actingAs($this->makeUser([], $world['sacco'])); // not the crew

        $this->postJson('/api/auth/book_a_ride/location', [
            'queue_id' => $queue->id, 'latitude' => -1.28, 'longitude' => 36.81,
        ])->assertStatus(403);
    }

    #[Test]
    public function nearby_returns_live_vehicles_within_radius(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);

        Sanctum::actingAs($world['owner']);
        $this->postJson('/api/auth/book_a_ride/location', [
            'queue_id' => $queue->id, 'latitude' => -1.2921, 'longitude' => 36.8219,
        ])->assertStatus(202);

        Sanctum::actingAs($this->makeUser([], $world['sacco']));

        // Right on top of it → found.
        $this->getJson('/api/auth/book_a_ride/nearby?latitude=-1.2921&longitude=36.8219&radius=2')
            ->assertOk()
            ->assertJsonCount(1, 'vehicles')
            ->assertJsonPath('vehicles.0.vehicle_id', $world['vehicle']->id)
            ->assertJsonPath('vehicles.0.plate', $world['vehicle']->plate);

        // Far away → nothing.
        $this->getJson('/api/auth/book_a_ride/nearby?latitude=0&longitude=0&radius=2')
            ->assertOk()->assertJsonCount(0, 'vehicles');
    }

    #[Test]
    public function the_driver_manifest_shows_pickup_and_dropoff_per_booking(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $passenger = $this->makeUser([], $world['sacco']);
        $booking = $this->makeBooking($queue, $passenger, $world['from'], $world['to'], 'Wanjiku');
        $this->makeSeatBooking($booking, $world['arrangements'][0]);

        Sanctum::actingAs($world['owner']);
        $this->getJson('/api/auth/book_a_ride/manifest/' . $queue->id)
            ->assertOk()
            ->assertJsonPath('queue_id', $queue->id)
            ->assertJsonCount(1, 'bookings')
            ->assertJsonPath('bookings.0.pickup.id', $world['from']->id)
            ->assertJsonPath('bookings.0.dropoff.id', $world['to']->id)
            ->assertJsonPath('pickups.0.place_id', $world['from']->id);

        // A passenger cannot read the manifest.
        Sanctum::actingAs($passenger);
        $this->getJson('/api/auth/book_a_ride/manifest/' . $queue->id)->assertStatus(403);
    }

    #[Test]
    public function the_same_seat_is_reused_across_non_overlapping_segments(): void
    {
        $world = $this->makeWorld(); // stages: from@0, to@40
        $mid = $this->makePlace('Ruiru');
        $this->makeRouteStage($world['route'], $mid, 20);
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $seat = (string) $world['arrangements'][0]->id;

        Sanctum::actingAs($this->makeUser([], $world['sacco']));

        // A: origin → Ruiru (0–20).
        $this->postJson('/api/auth/book_a_ride/booking/add', [
            'id' => $queue->id, 'seats' => $seat, 'name' => 'A', 'phone' => '0722000001',
            'fromId' => $world['from']->id, 'toId' => $mid->id,
        ])->assertOk();

        // B: Ruiru → Thika (20–40) on the SAME seat — no overlap, allowed.
        $this->postJson('/api/auth/book_a_ride/booking/add', [
            'id' => $queue->id, 'seats' => $seat, 'name' => 'B', 'phone' => '0722000002',
            'fromId' => $mid->id, 'toId' => $world['to']->id,
        ])->assertOk();

        // C: origin → Thika (0–40) overlaps both — same seat rejected.
        $this->postJson('/api/auth/book_a_ride/booking/add', [
            'id' => $queue->id, 'seats' => $seat, 'name' => 'C', 'phone' => '0722000003',
            'fromId' => $world['from']->id, 'toId' => $world['to']->id,
        ])->assertStatus(400);

        // The seat map agrees: free for the still-open first leg, taken end-to-end.
        $mapFree = '/api/auth/book_a_ride/seats?bus_id=' . $world['vehicle']->id . '&id=' . $queue->id
            . '&from_id=' . $world['from']->id . '&to_id=' . $mid->id;
        // origin→Ruiru is taken by A, so it should report the seat booked:
        $this->getJson($mapFree)->assertOk()->assertJsonCount(1, 'booked');
    }
}
