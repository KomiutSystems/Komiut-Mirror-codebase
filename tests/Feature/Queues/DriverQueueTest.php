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
 * (App\Http\Controllers\APIs\Driver\DriverQueueController): a driver puts the
 * vehicle they are assigned to — never a client-supplied vehicle/status — in a
 * STAGE's line, then departs or exits. Since 2026-10-06 joining takes a stage
 * and nothing else; the route a bus runs is chosen by going live.
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

    /** A live run on the world's route, as going live makes one. */
    private function goLive(array $world, User $driver): Queue
    {
        $active = \App\Models\QueueStatus::where('status', 'Active')->first()
            ?? $this->makeQueueStatus('Active', 'Active');

        return $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $active, $driver, 'LIVE');
    }

    #[Test]
    public function a_driver_joins_a_queue_with_only_a_stage(): void
    {
        $world = $this->makeWorld();
        $this->makeQueueStatus('Pending', 'Pending');
        $driver = $this->makeAssignedDriver($world);
        Sanctum::actingAs($driver);

        $response = $this->postJson('/api/auth/queues/join', [
            'terminus_id' => $world['terminus']->id,
        ])->assertStatus(201);

        $queue = Queue::firstOrFail();
        // Vehicle came from the assignment, not the body.
        $this->assertSame($world['vehicle']->id, $queue->vehicle_id);
        // A place in a line: no route, no fare, no pick-up points, first in line.
        $this->assertSame(Queue::KIND_STAGE, $queue->kind);
        $this->assertNull($queue->route_id);
        $this->assertEquals(0, $queue->amount);
        $this->assertSame(1, (int) $queue->position);
        $this->assertSame(0, QueuePlace::where('queue_id', $queue->id)->count());
        $response->assertJsonPath('queue.vehicle_id', $world['vehicle']->id)
            ->assertJsonPath('queue.kind', Queue::KIND_STAGE)
            ->assertJsonPath('queue.route_id', null);
    }

    #[Test]
    public function a_route_sent_by_an_older_app_is_ignored(): void
    {
        // KDN 458N, 2026-10-06: the app picked the first route out of the stage
        // tapped and the bus was queued on Ambassadeur - Alsops. A route here is
        // no longer read at all -- not even checked against the stage.
        $world = $this->makeWorld();
        $this->makeQueueStatus('Pending', 'Pending');
        $driver = $this->makeAssignedDriver($world);
        $elsewhere = $this->makeRoute($this->makePlace('Ambassadeur'), $this->makePlace('Alsops'), $world['sacco']);
        Sanctum::actingAs($driver);

        $this->postJson('/api/auth/queues/join', [
            'terminus_id' => $world['terminus']->id,
            'route_id' => $elsewhere->id,
        ])->assertStatus(201)->assertJsonPath('queue.route_id', null);

        $this->assertSame(0, Queue::whereNotNull('route_id')->count());
    }

    #[Test]
    public function any_stage_of_the_sacco_can_be_joined_not_only_a_route_origin(): void
    {
        $world = $this->makeWorld();
        $this->makeQueueStatus('Pending', 'Pending');
        $driver = $this->makeAssignedDriver($world);
        // A stage at the route's DESTINATION: refused while joining took a route.
        $thika = $this->makeTerminus($world['to']);
        \App\Models\SaccoTerminus::create(['terminus_id' => $thika->id, 'sacco_id' => $world['sacco']->id, 'user_id' => $world['owner']->id]);
        Sanctum::actingAs($driver);

        $this->postJson('/api/auth/queues/join', ['terminus_id' => $thika->id])
            ->assertStatus(201)
            ->assertJsonPath('queue.terminus_id', $thika->id);
    }

    #[Test]
    public function a_stage_not_assigned_to_the_sacco_is_refused(): void
    {
        $world = $this->makeWorld();
        $this->makeQueueStatus('Pending', 'Pending');
        $driver = $this->makeAssignedDriver($world);
        $foreign = $this->makeTerminus($world['to']);
        Sanctum::actingAs($driver);

        $this->postJson('/api/auth/queues/join', ['terminus_id' => $foreign->id])
            ->assertStatus(422)
            ->assertJsonPath('error', 'This terminus is not assigned to your SACCO.');

        $this->assertSame(0, Queue::count());
    }

    #[Test]
    public function a_driver_without_an_assignment_cannot_join(): void
    {
        $world = $this->makeWorld();
        $this->makeQueueStatus('Pending', 'Pending');
        Sanctum::actingAs($this->makeUser(['Edit Queues'], $world['sacco']));

        $this->postJson('/api/auth/queues/join', [
            'terminus_id' => $world['terminus']->id,
        ])->assertStatus(403);

        $this->assertSame(0, Queue::count());
    }

    #[Test]
    public function re_joining_the_same_stage_returns_the_existing_queue(): void
    {
        $world = $this->makeWorld();
        $this->makeQueueStatus('Pending', 'Pending');
        $driver = $this->makeAssignedDriver($world);
        Sanctum::actingAs($driver);

        $first = $this->postJson('/api/auth/queues/join', [
            'terminus_id' => $world['terminus']->id,
        ])->assertStatus(201)->json('queue.id');

        $this->postJson('/api/auth/queues/join', [
            'terminus_id' => $world['terminus']->id,
        ])->assertOk()->assertJsonPath('queue.id', $first);

        $this->assertSame(1, Queue::count());
    }

    #[Test]
    public function joining_another_stage_while_in_a_line_is_refused(): void
    {
        $world = $this->makeWorld();
        $this->makeQueueStatus('Pending', 'Pending');
        $driver = $this->makeAssignedDriver($world);
        $thika = $this->makeTerminus($world['to']);
        \App\Models\SaccoTerminus::create(['terminus_id' => $thika->id, 'sacco_id' => $world['sacco']->id, 'user_id' => $world['owner']->id]);
        Sanctum::actingAs($driver);

        $this->postJson('/api/auth/queues/join', ['terminus_id' => $world['terminus']->id])->assertStatus(201);

        $this->postJson('/api/auth/queues/join', ['terminus_id' => $thika->id])
            ->assertStatus(409);

        $this->assertSame(1, Queue::count());
    }

    #[Test]
    public function being_live_on_a_route_neither_blocks_nor_is_reused_by_joining(): void
    {
        $world = $this->makeWorld();
        $this->makeQueueStatus('Pending', 'Pending');
        $driver = $this->makeAssignedDriver($world);
        $run = $this->goLive($world, $driver);
        Sanctum::actingAs($driver);

        $stageId = $this->postJson('/api/auth/queues/join', ['terminus_id' => $world['terminus']->id])
            ->assertStatus(201)
            ->assertJsonPath('queue.kind', Queue::KIND_STAGE)
            ->json('queue.id');

        $this->assertNotSame($run->id, $stageId);
        // The run is untouched: still live, still on its route.
        $this->assertSame(Queue::KIND_LIVE, $run->fresh()->kind);
        $this->assertSame($world['route']->id, (int) $run->fresh()->route_id);
    }

    #[Test]
    public function two_buses_at_one_stage_share_one_line_whatever_route_they_will_run(): void
    {
        $world = $this->makeWorld();
        $this->makeQueueStatus('Pending', 'Pending');
        $first = $this->makeAssignedDriver($world);
        $second = $this->makeUser(['Edit Queues'], $world['sacco']);
        $otherBus = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);
        VehicleUser::create(['user_id' => $second->id, 'vehicle_id' => $otherBus->id, 'sacco_id' => $world['sacco']->id, 'status' => true]);

        Sanctum::actingAs($first);
        $this->postJson('/api/auth/queues/join', ['terminus_id' => $world['terminus']->id])
            ->assertStatus(201)->assertJsonPath('queue.position', 1);

        Sanctum::actingAs($second);
        $this->postJson('/api/auth/queues/join', ['terminus_id' => $world['terminus']->id])
            ->assertStatus(201)->assertJsonPath('queue.position', 2);
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
        ])->assertStatus(201);

        $this->postJson('/api/auth/queues/exit')
            ->assertOk()
            ->assertJson(['success' => 'Left the queue.']);

        $this->assertSame($cancelled->id, Queue::firstOrFail()->queue_status_id);
    }

    #[Test]
    public function exiting_a_line_leaves_the_live_run_alone(): void
    {
        $world = $this->makeWorld();
        $this->makeQueueStatus('Pending', 'Pending');
        $this->makeQueueStatus('Cancelled', 'Cancelled');
        $driver = $this->makeAssignedDriver($world);
        $run = $this->goLive($world, $driver);
        Sanctum::actingAs($driver);

        // Live, but in no line: nothing to leave.
        $this->postJson('/api/auth/queues/exit')->assertStatus(404);
        $this->assertSame('Active', $run->fresh()->queue_status->status);
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
    public function the_driver_sees_the_live_runs_bookings_in_the_mobile_shape(): void
    {
        $world = $this->makeWorld();
        $this->makeQueueStatus('Pending', 'Pending');
        $driver = $this->makeAssignedDriver($world);
        $run = $this->goLive($world, $driver);
        Sanctum::actingAs($driver);

        $passenger = $this->makeUser([], $world['sacco']);
        $booking = $this->makeBooking($run, $passenger, $world['from'], $world['to'], 'Wanjiku');

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
        $run = $this->goLive($world, $driver);
        Sanctum::actingAs($driver);

        $passenger = $this->makeUser([], $world['sacco']);
        $this->makeBooking($run, $passenger, $world['from'], $world['to'], 'Wanjiku');
        $cancelled = $this->makeBooking($run, $passenger, $world['from'], $world['to'], 'Otieno');
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
}
