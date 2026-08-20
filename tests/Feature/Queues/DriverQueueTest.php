<?php

declare(strict_types=1);

namespace Tests\Feature\Queues;

use App\Models\Queue;
use App\Models\QueuePlace;
use App\Models\User;
use App\Models\VehicleUser;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;

/**
 * Driver-facing queue/trip lifecycle
 * (App\Http\Controllers\APIs\Driver\DriverQueueController): a driver queues the
 * vehicle they are assigned to — never a client-supplied vehicle/fare/status —
 * then starts or exits. Mirrors the C# Queue/join, Queue/exit, Trips/start-trip.
 */
final class DriverQueueTest extends QueueTestCase
{
    /** A driver holding Edit Queues, actively assigned to the world's vehicle. */
    private function makeAssignedDriver(array $world): User
    {
        $driver = $this->makeUser(['Edit Queues'], $world['sacco']);
        VehicleUser::create([
            'user_id' => $driver->id,
            'vehicle_id' => $world['vehicle']->id,
            'sacco_id' => $world['sacco']->id,
            'status' => true,
        ]);

        return $driver;
    }

    #[Test]
    public function a_driver_joins_a_queue_with_only_terminus_and_route(): void
    {
        $world = $this->makeWorld();
        $this->makeQueueStatus('Pending', 'Pending');
        $driver = $this->makeAssignedDriver($world);
        Sanctum::actingAs($driver);

        $response = $this->postJson('/api/auth/queues/join', [
            'terminus_id' => $world['terminus']->id,
            'route_id' => $world['route']->id,
        ])->assertStatus(201);

        $queue = Queue::firstOrFail();
        // Vehicle came from the assignment, not the body.
        $this->assertSame($world['vehicle']->id, $queue->vehicle_id);
        // Fare came from the SACCO's route price (makeWorld sets a 200 flat fare).
        $this->assertEquals(200, $queue->amount);
        $response->assertJsonPath('queue.vehicle_id', $world['vehicle']->id);
        // Pickup points along the route were materialised.
        $this->assertSame(
            QueuePlace::where('queue_id', $queue->id)->count(),
            count($world['stages'])
        );
    }

    #[Test]
    public function a_driver_without_an_assignment_cannot_join(): void
    {
        $world = $this->makeWorld();
        $this->makeQueueStatus('Pending', 'Pending');
        Sanctum::actingAs($this->makeUser(['Edit Queues'], $world['sacco']));

        $this->postJson('/api/auth/queues/join', [
            'terminus_id' => $world['terminus']->id,
            'route_id' => $world['route']->id,
        ])->assertStatus(403);

        $this->assertSame(0, Queue::count());
    }

    #[Test]
    public function re_joining_the_same_route_returns_the_existing_queue(): void
    {
        $world = $this->makeWorld();
        $this->makeQueueStatus('Pending', 'Pending');
        $driver = $this->makeAssignedDriver($world);
        Sanctum::actingAs($driver);

        $first = $this->postJson('/api/auth/queues/join', [
            'terminus_id' => $world['terminus']->id,
            'route_id' => $world['route']->id,
        ])->assertStatus(201)->json('queue.id');

        $this->postJson('/api/auth/queues/join', [
            'terminus_id' => $world['terminus']->id,
            'route_id' => $world['route']->id,
        ])->assertOk()->assertJsonPath('queue.id', $first);

        $this->assertSame(1, Queue::count());
    }

    #[Test]
    public function starting_a_trip_moves_the_queue_to_active(): void
    {
        $world = $this->makeWorld();
        $this->makeQueueStatus('Pending', 'Pending');
        $active = $this->makeQueueStatus('Active', 'Active');
        $driver = $this->makeAssignedDriver($world);
        Sanctum::actingAs($driver);

        $this->postJson('/api/auth/queues/join', [
            'terminus_id' => $world['terminus']->id,
            'route_id' => $world['route']->id,
        ])->assertStatus(201);

        $this->postJson('/api/auth/trips/start')
            ->assertOk()
            ->assertJsonPath('queue.queue_status_id', $active->id);

        $this->assertSame($active->id, Queue::firstOrFail()->queue_status_id);
    }

    #[Test]
    public function exiting_cancels_the_current_queue(): void
    {
        $world = $this->makeWorld();
        $this->makeQueueStatus('Pending', 'Pending');
        $cancelled = $this->makeQueueStatus('Cancelled', 'Cancelled');
        $driver = $this->makeAssignedDriver($world);
        Sanctum::actingAs($driver);

        $this->postJson('/api/auth/queues/join', [
            'terminus_id' => $world['terminus']->id,
            'route_id' => $world['route']->id,
        ])->assertStatus(201);

        $this->postJson('/api/auth/queues/exit')
            ->assertOk()
            ->assertJson(['success' => 'Left the queue.']);

        $this->assertSame($cancelled->id, Queue::firstOrFail()->queue_status_id);
    }

    #[Test]
    public function starting_a_trip_without_a_queue_is_a_404(): void
    {
        $world = $this->makeWorld();
        $driver = $this->makeAssignedDriver($world);
        Sanctum::actingAs($driver);

        $this->postJson('/api/auth/trips/start')->assertStatus(404);
    }

    #[Test]
    public function the_driver_sees_current_trip_bookings_in_the_mobile_shape(): void
    {
        $world = $this->makeWorld();
        $this->makeQueueStatus('Pending', 'Pending');
        $driver = $this->makeAssignedDriver($world);
        Sanctum::actingAs($driver);

        $queueId = $this->postJson('/api/auth/queues/join', [
            'terminus_id' => $world['terminus']->id,
            'route_id' => $world['route']->id,
        ])->assertStatus(201)->json('queue.id');

        $passenger = $this->makeUser([], $world['sacco']);
        $booking = $this->makeBooking(
            Queue::find($queueId), $passenger, $world['from'], $world['to'], 'Wanjiku'
        );

        $this->getJson('/api/auth/trips/bookings')
            ->assertOk()
            ->assertJsonPath('bookings.0.bookingId', $booking->id)
            ->assertJsonPath('bookings.0.passengerName', 'Wanjiku')
            ->assertJsonPath('bookings.0.bookingType', 'route')
            ->assertJsonPath('bookings.0.pickup.id', $world['from']->id)
            ->assertJsonPath('bookings.0.dropoff.id', $world['to']->id);
    }

    #[Test]
    public function trip_bookings_include_cancelled_and_carry_a_status_label(): void
    {
        $world = $this->makeWorld();
        $this->makeQueueStatus('Pending', 'Pending');
        $driver = $this->makeAssignedDriver($world);
        Sanctum::actingAs($driver);

        $queueId = $this->postJson('/api/auth/queues/join', [
            'terminus_id' => $world['terminus']->id,
            'route_id' => $world['route']->id,
        ])->assertStatus(201)->json('queue.id');

        $passenger = $this->makeUser([], $world['sacco']);
        $this->makeBooking(Queue::find($queueId), $passenger, $world['from'], $world['to'], 'Wanjiku');
        $cancelled = $this->makeBooking(Queue::find($queueId), $passenger, $world['from'], $world['to'], 'Otieno');
        $cancelled->forceFill(['status' => false])->save();

        // Both are returned now — the cancelled one used to be silently hidden.
        $res = $this->getJson('/api/auth/trips/bookings')->assertOk()->assertJsonCount(2, 'bookings');
        $labels = collect($res->json('bookings'))->pluck('status_label')->all();
        $this->assertContains('failed', $labels);
        $this->assertContains('reserved', $labels);

        // Filterable to just the cancelled one.
        $this->getJson('/api/auth/trips/bookings?booking_status=failed')
            ->assertOk()
            ->assertJsonCount(1, 'bookings')
            ->assertJsonPath('bookings.0.status_label', 'failed');
    }

    #[Test]
    public function trip_bookings_are_empty_when_not_queued(): void
    {
        $world = $this->makeWorld();
        $driver = $this->makeAssignedDriver($world);
        Sanctum::actingAs($driver);

        $this->getJson('/api/auth/trips/bookings')
            ->assertOk()
            ->assertJsonCount(0, 'bookings');
    }

    #[Test]
    public function joining_rejects_a_terminus_that_is_not_the_route_origin(): void
    {
        $world = $this->makeWorld();
        $this->makeQueueStatus('Pending', 'Pending');
        $driver = $this->makeAssignedDriver($world);
        // A terminus at the DESTINATION, not the route origin.
        $wrongTerminus = $this->makeTerminus($world['to']);
        Sanctum::actingAs($driver);

        $this->postJson('/api/auth/queues/join', [
            'terminus_id' => $wrongTerminus->id,
            'route_id' => $world['route']->id,
        ])->assertStatus(422);

        $this->assertSame(0, Queue::count());
    }
}
