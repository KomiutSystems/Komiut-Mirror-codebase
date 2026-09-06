<?php

declare(strict_types=1);

namespace Tests\Feature\Queues;

use App\Enums\UserType;
use App\Events\VehicleMoved;
use App\Models\VehicleUser;
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
            ->assertJsonPath('vehicles.0.plate', $world['vehicle']->plate)
            // The passenger's "approaching" screen names the route. It used to
            // get route_id only and rendered the route/destination blank, which
            // looked like "no data" rather than a missing field.
            ->assertJsonPath('vehicles.0.route_id', $world['route']->id)
            ->assertJsonPath('vehicles.0.route_name', $world['route']->name);

        // Far away → nothing.
        $this->getJson('/api/auth/book_a_ride/nearby?latitude=0&longitude=0&radius=2')
            ->assertOk()->assertJsonCount(0, 'vehicles');
    }

    #[Test]
    public function the_nearby_item_shape_is_exactly_this_snake_case_key_set(): void
    {
        // The mobile DTO for this endpoint was inherited from the old C#
        // gateway and parses camelCase (vehicleId, registrationNumber,
        // lastUpdatedAt). A rename on this side to "meet it halfway" would
        // silently blank the field for every other client instead of fixing
        // anything, so the key set is pinned here: snake_case, like the rest
        // of the API, and the envelope stays `vehicles`.
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);

        Sanctum::actingAs($world['owner']);
        $this->postJson('/api/auth/book_a_ride/location', [
            'queue_id' => $queue->id, 'latitude' => -1.2921, 'longitude' => 36.8219,
        ])->assertStatus(202);

        Sanctum::actingAs($this->makeUser([], $world['sacco']));
        $item = $this->getJson('/api/auth/book_a_ride/nearby?latitude=-1.2921&longitude=36.8219&radius=2')
            ->assertOk()->json('vehicles.0');

        $this->assertSame([
            'vehicle_id', 'plate', 'capacity', 'sacco', 'route_id', 'route_name',
            'queue_id', 'latitude', 'longitude', 'heading', 'distance_km', 'recorded_at',
        ], array_keys($item));

        $this->assertSame($queue->id, $item['queue_id']);
        $this->assertIsInt($item['vehicle_id'], 'vehicle_id is an integer id, not a string/uuid.');
        // Mobile hard-casts capacity to int; a stringy "14" from PDO would crash
        // it on a field that looks fine in the JSON.
        $this->assertIsInt($item['capacity'], 'capacity must be a JSON number, not a quoted string.');
        $this->assertNotNull($item['recorded_at']);
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
        $this->getJson('/api/auth/book_a_ride/manifest/'.$queue->id)
            ->assertOk()
            ->assertJsonPath('queue_id', $queue->id)
            ->assertJsonCount(1, 'bookings')
            ->assertJsonPath('bookings.0.pickup.id', $world['from']->id)
            ->assertJsonPath('bookings.0.dropoff.id', $world['to']->id)
            ->assertJsonPath('pickups.0.place_id', $world['from']->id);

        // A passenger cannot read the manifest.
        Sanctum::actingAs($passenger);
        $this->getJson('/api/auth/book_a_ride/manifest/'.$queue->id)->assertStatus(403);
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
        $mapFree = '/api/auth/book_a_ride/seats?bus_id='.$world['vehicle']->id.'&id='.$queue->id
            .'&from_id='.$world['from']->id.'&to_id='.$mid->id;
        // origin→Ruiru is taken by A, so it should report the seat booked:
        $this->getJson($mapFree)->assertOk()->assertJsonCount(1, 'booked');
    }

    #[Test]
    public function the_driver_app_does_not_have_to_send_a_queue_id(): void
    {
        // The app posts {vehicleId, latitude, longitude, routeId} every four
        // seconds and never sends queue_id -- a required queue_id 400'd every
        // GPS ping, so the matatu never appeared on the live map at all.
        $world = $this->makeWorld();
        $queue = $this->makeQueue(
            $world['vehicle'], $world['terminus'], $world['route'],
            $this->makeQueueStatus('Active '.$this->nextSequence(), 'Active'),
            $world['owner'], 'QN-'.$this->nextSequence(),
        );

        $driver = $this->makeUser([], $world['sacco']);
        $driver->forceFill(['type' => UserType::Driver])->save();
        VehicleUser::create([
            'user_id' => $driver->id, 'vehicle_id' => $world['vehicle']->id,
            'sacco_id' => $world['sacco']->id, 'status' => true, 'start_date' => now(),
        ]);

        Sanctum::actingAs($driver);

        // Exactly what the app sends -- extra camelCase keys and all.
        $this->postJson('/api/v1/auth/book_a_ride/location', [
            'vehicleId' => $world['vehicle']->id,
            'latitude' => -1.2833,
            'longitude' => 36.8167,
            'routeId' => '',
        ])->assertStatus(202);

        $this->assertDatabaseHas('vehicle_locations', [
            'vehicle_id' => $world['vehicle']->id,
            'broadcasting' => true,
        ]);

        $this->postJson('/api/v1/auth/book_a_ride/location/stop', [
            'vehicleId' => $world['vehicle']->id,
        ])->assertOk();

        $this->assertSame($queue->id, $queue->fresh()->id);
    }

    /** A driver with an open assignment and no trip in progress. */
    private function assignedDriver(array $world)
    {
        $driver = $this->makeUser([], $world['sacco']);
        $driver->forceFill(['type' => UserType::Driver])->save();
        VehicleUser::create([
            'user_id' => $driver->id, 'vehicle_id' => $world['vehicle']->id,
            'sacco_id' => $world['sacco']->id, 'status' => true, 'start_date' => now(),
        ]);

        return $driver;
    }

    #[Test]
    public function a_driver_can_go_live_without_being_on_a_trip(): void
    {
        // BEING LIVE AND BEING ON A TRIP ARE INDEPENDENT. This used to answer
        // 422 "You are not currently on a trip", which welded location
        // broadcasting to the queue lifecycle: a driver could not appear on the
        // map while waiting at the stage, and a bus already running with the app
        // reopened mid-route could not start broadcasting at all.
        //
        // Going live is a driver saying "I am here, on this route"; the queue is
        // a separate fact about the stage.
        $world = $this->makeWorld();
        Sanctum::actingAs($this->assignedDriver($world));

        $this->postJson('/api/v1/auth/book_a_ride/location', [
            'latitude' => -1.2833, 'longitude' => 36.8167,
        ])->assertStatus(202)->assertJsonPath('status', 'broadcasting');

        $this->assertDatabaseHas('vehicle_locations', [
            'vehicle_id' => $world['vehicle']->id,
            'broadcasting' => true,
            'queue_id' => null,
        ]);
    }

    #[Test]
    public function a_driver_can_go_offline_after_the_trip_has_ended(): void
    {
        // THE WORSE HALF OF THE SAME FAULT. Stopping also resolved a live trip,
        // so a driver who ended their trip and then tried to go offline had
        // nothing left to resolve -- the stop was refused and the bus kept
        // showing as broadcasting until the record went stale on its own.
        $world = $this->makeWorld();
        Sanctum::actingAs($this->assignedDriver($world));

        $this->postJson('/api/v1/auth/book_a_ride/location', [
            'latitude' => -1.2833, 'longitude' => 36.8167,
        ])->assertStatus(202);

        $this->postJson('/api/v1/auth/book_a_ride/location/stop')->assertOk();

        $this->assertDatabaseHas('vehicle_locations', [
            'vehicle_id' => $world['vehicle']->id,
            'broadcasting' => false,
        ]);
    }

    #[Test]
    public function a_live_bus_can_name_the_route_it_is_running(): void
    {
        // `nearby` filters on route_id, and a queue-less ping has no queue to
        // borrow one from. Without this a driver broadcasting off-queue is
        // invisible to every route-filtered search -- live, and findable by
        // nobody looking for their route.
        $world = $this->makeWorld();
        Sanctum::actingAs($this->assignedDriver($world));

        $this->postJson('/api/v1/auth/book_a_ride/location', [
            'latitude' => -1.2833, 'longitude' => 36.8167,
            'route_id' => $world['route']->id,
        ])->assertStatus(202);

        $this->assertDatabaseHas('vehicle_locations', [
            'vehicle_id' => $world['vehicle']->id,
            'route_id' => $world['route']->id,
            'queue_id' => null,
        ]);
    }

    #[Test]
    public function a_ping_during_a_trip_still_carries_the_queue(): void
    {
        // The trip channel is what passengers who booked this queue subscribe
        // to, so decoupling must not cost them the moving pin.
        $world = $this->makeWorld();
        $active = $this->makeQueueStatus('Active', 'Active');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $active, $world['owner']);

        Sanctum::actingAs($this->assignedDriver($world));

        $this->postJson('/api/v1/auth/book_a_ride/location', [
            'latitude' => -1.2833, 'longitude' => 36.8167,
        ])->assertStatus(202);

        $this->assertDatabaseHas('vehicle_locations', [
            'vehicle_id' => $world['vehicle']->id,
            'queue_id' => $queue->id,
        ]);
    }
}
