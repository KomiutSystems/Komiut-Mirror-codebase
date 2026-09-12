<?php

declare(strict_types=1);

namespace Tests\Feature\Queues;

use App\Models\Queue;
use App\Models\QueuePlace;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;

/**
 * Regression coverage for App\Http\Controllers\APIs\Dashboard\Queues\QueuesAPIController.
 *
 * These assert CURRENT behaviour, including behaviour that looks wrong.
 */
final class QueuesApiTest extends QueueTestCase
{
    #[Test]
    public function a_permitted_user_can_add_a_queue_and_it_gets_a_generated_queue_number(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $user = $this->makeUser(['Add Queues'], $world['sacco']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/auth/queues/add', [
            'id' => 0,
            'vehicle' => $world['vehicle']->id,
            'terminus' => $world['terminus']->id,
            'status' => $pending->id,
            'route' => $world['route']->id,
            'choice' => 0,
            'amount' => 200,
        ]);

        $response->assertOk()->assertJson(['success' => 'Queue updated successfully!']);

        $queue = Queue::firstOrFail();
        $this->assertSame('QN-1', $queue->queue_number);
        $this->assertSame($world['vehicle']->id, $queue->vehicle_id);
        $this->assertSame($pending->id, $queue->queue_status_id);
        $this->assertSame($user->id, $queue->user_id);
        $this->assertNotNull($queue->start_time);
        $this->assertNull($queue->schedule_time);

        // Every route stage is mirrored into queue_places when the queue is created.
        $this->assertSame(2, QueuePlace::where('queue_id', $queue->id)->count());
    }

    #[Test]
    public function queue_numbers_increment_per_terminus_and_route_giving_the_fifo_ordering(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $this->makeQueueStatus('Completed', 'Completed');
        $secondVehicle = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);
        $user = $this->makeUser(['Add Queues'], $world['sacco']);
        Sanctum::actingAs($user);

        $payload = [
            'id' => 0,
            'terminus' => $world['terminus']->id,
            'status' => $pending->id,
            'route' => $world['route']->id,
            'choice' => 0,
            'amount' => 200,
        ];

        $this->postJson('/api/auth/queues/add', $payload + ['vehicle' => $world['vehicle']->id])->assertOk();
        $this->postJson('/api/auth/queues/add', $payload + ['vehicle' => $secondVehicle->id])->assertOk();

        $this->assertSame(
            ['QN-1', 'QN-2'],
            Queue::orderBy('id')->pluck('queue_number')->all()
        );
    }

    #[Test]
    public function the_office_cannot_re_queue_a_vehicle_that_is_on_a_trip(): void
    {
        // Decided 2026-09-12: ending a trip is the crew's action. This used to
        // silently mark the vehicle's open queue Completed so the new one could
        // take its place -- a live trip, with paid passengers waiting on it,
        // ended from a desk that cannot see the bus.
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $this->makeQueueStatus('Completed', 'Completed');
        $existing = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $user = $this->makeUser(['Add Queues'], $world['sacco']);
        Sanctum::actingAs($user);

        $this->postJson('/api/auth/queues/add', [
            'id' => 0,
            'vehicle' => $world['vehicle']->id,
            'terminus' => $world['terminus']->id,
            'status' => $pending->id,
            'route' => $world['route']->id,
            'choice' => 0,
            'amount' => 200,
        ])->assertStatus(409)
            ->assertJson(['error' => 'This vehicle is already on a trip. The crew ends it from the bus; queue it again after that.']);

        $this->assertSame($pending->id, $existing->fresh()->queue_status_id, 'the live trip is untouched');
        $this->assertSame(1, Queue::count());
    }

    #[Test]
    public function the_office_cannot_end_a_trip_by_editing_its_status(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $completed = $this->makeQueueStatus('Completed', 'Completed');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $user = $this->makeUser(['Add Queues', 'Edit Queues'], $world['sacco']);
        Sanctum::actingAs($user);

        $this->postJson('/api/auth/queues/add', [
            'id' => $queue->id,
            'vehicle' => $world['vehicle']->id,
            'terminus' => $world['terminus']->id,
            'status' => $completed->id,
            'route' => $world['route']->id,
            'choice' => 0,
            'amount' => 200,
        ])->assertStatus(409)
            ->assertJson(['error' => 'Ending a trip is a crew action. The driver ends it from the bus.']);

        $this->assertSame($pending->id, $queue->fresh()->queue_status_id);
    }

    #[Test]
    public function adding_a_queue_requires_a_terminus_whose_place_matches_the_route_origin(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $otherTerminus = $this->makeTerminus($world['to']);
        $user = $this->makeUser(['Add Queues'], $world['sacco']);
        Sanctum::actingAs($user);

        $this->postJson('/api/auth/queues/add', [
            'id' => 0,
            'vehicle' => $world['vehicle']->id,
            'terminus' => $otherTerminus->id,
            'status' => $pending->id,
            'route' => $world['route']->id,
            'choice' => 0,
            'amount' => 200,
        ])->assertStatus(401)->assertJson(['error' => 'Terminus has a different place from route']);

        $this->assertSame(0, Queue::count());
    }

    #[Test]
    public function adding_a_queue_validates_its_input(): void
    {
        $world = $this->makeWorld();
        $user = $this->makeUser(['Add Queues'], $world['sacco']);
        Sanctum::actingAs($user);

        $this->postJson('/api/auth/queues/add', ['id' => 0])
            ->assertStatus(400)
            ->assertJsonStructure(['errors' => ['vehicle', 'terminus', 'status', 'route', 'choice', 'amount']]);
    }

    #[Test]
    public function a_scheduled_queue_in_the_past_is_rejected(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $user = $this->makeUser(['Add Queues'], $world['sacco']);
        Sanctum::actingAs($user);

        // Was HTTP 200 with an `error` key — a refusal the dashboard read as a
        // success, so the admin saw nothing and the queue silently did not
        // exist. Now a 422 that says what to do.
        $this->postJson('/api/auth/queues/add', [
            'id' => 0,
            'vehicle' => $world['vehicle']->id,
            'terminus' => $world['terminus']->id,
            'status' => $pending->id,
            'route' => $world['route']->id,
            'choice' => 1,
            'schedule_time' => now()->subDay()->toDateTimeString(),
            'amount' => 200,
        ])->assertStatus(422)
            ->assertJsonPath('message', 'That departure time has already passed. Pick a time later than now.');

        $this->assertSame(0, Queue::count());
    }

    #[Test]
    public function a_user_without_the_queue_permissions_cannot_add_a_queue(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $user = $this->makeUser(['View Queues'], $world['sacco']);
        Sanctum::actingAs($user);

        $this->postJson('/api/auth/queues/add', [
            'id' => 0,
            'vehicle' => $world['vehicle']->id,
            'terminus' => $world['terminus']->id,
            'status' => $pending->id,
            'route' => $world['route']->id,
            'choice' => 0,
            'amount' => 200,
        ])->assertStatus(401)
            ->assertJson(['error' => 'You do not have permissione to Add/Edit Queues']);

        $this->assertSame(0, Queue::count());
    }

    #[Test]
    public function edit_queues_alone_cannot_add_a_queue(): void
    {
        // A driver holds 'Edit Queues' (for completeQueue / trips:start) but must
        // NOT be able to dispatch-queue an arbitrary vehicle via queues/add.
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $user = $this->makeUser(['Edit Queues'], $world['sacco']);
        Sanctum::actingAs($user);

        $this->postJson('/api/auth/queues/add', [
            'id' => 0,
            'vehicle' => $world['vehicle']->id,
            'terminus' => $world['terminus']->id,
            'status' => $pending->id,
            'route' => $world['route']->id,
            'choice' => 0,
            'amount' => 200,
        ])->assertStatus(401);

        $this->assertSame(0, Queue::count());
    }

    #[Test]
    public function an_inactive_user_is_blocked_by_the_user_status_middleware(): void
    {
        $world = $this->makeWorld();
        $user = $this->makeUser(['Add Queues'], $world['sacco'], status: false);
        Sanctum::actingAs($user);

        $this->getJson('/api/auth/queues')
            ->assertStatus(403)
            ->assertJsonStructure(['inactive']);
    }

    #[Test]
    public function a_user_attached_to_an_inactive_sacco_is_blocked(): void
    {
        $sacco = $this->makeSacco(active: false);
        $user = $this->makeUser(['Add Queues'], $sacco);
        Sanctum::actingAs($user);

        $this->getJson('/api/auth/queues')
            ->assertStatus(403)
            ->assertJsonStructure(['inactive']);
    }

    #[Test]
    public function listing_queues_returns_todays_queues_with_their_relations(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $user = $this->makeUser(['View Queues'], $world['sacco']);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/auth/queues');

        $response->assertOk()
            ->assertJsonCount(1, 'queues')
            ->assertJsonPath('queues.0.id', $queue->id)
            ->assertJsonPath('queues.0.queue_number', 'QN-1')
            ->assertJsonPath('queues.0.vehicle.plate', $world['vehicle']->plate)
            ->assertJsonPath('queues.0.queue_status.status', 'Pending');
    }

    #[Test]
    public function listing_queues_excludes_queues_created_outside_the_requested_day(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $queue->forceFill(['created_at' => now()->subDays(3)])->save();
        $user = $this->makeUser(['View Queues'], $world['sacco']);
        Sanctum::actingAs($user);

        $this->getJson('/api/auth/queues')->assertOk()->assertJsonCount(0, 'queues');
    }

    #[Test]
    public function listing_queues_can_be_filtered_by_vehicle_plate_search(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $otherVehicle = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);
        $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner'], 'QN-1');
        $this->makeQueue($otherVehicle, $world['terminus'], $world['route'], $pending, $world['owner'], 'QN-2');
        $user = $this->makeUser(['View Queues'], $world['sacco']);
        Sanctum::actingAs($user);

        $this->getJson('/api/auth/queues?search=' . $otherVehicle->plate)
            ->assertOk()
            ->assertJsonCount(1, 'queues')
            ->assertJsonPath('queues.0.queue_number', 'QN-2');
    }

    #[Test]
    public function viewing_a_single_queue_returns_the_queue_and_a_bad_id_returns_401(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $user = $this->makeUser(['View Queues'], $world['sacco']);
        Sanctum::actingAs($user);

        $this->getJson('/api/auth/queues/view/' . $queue->id)
            ->assertOk()
            ->assertJsonPath('queue.id', $queue->id)
            ->assertJsonPath('queue.queue_status.status', 'Pending');

        $this->getJson('/api/auth/queues/view/999999')
            ->assertStatus(401)
            ->assertJson(['error' => 'Invalid queue ID']);
    }

    #[Test]
    public function viewing_queue_bookings_returns_the_bookings_for_that_queue_only(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner'], 'QN-1');
        $otherQueue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner'], 'QN-2');

        // View Passengers, not just View Queues. This endpoint returns the
        // PASSENGER LIST — names, phones, and a full creator row — and the
        // production Driver and Conductor roles both hold View Queues, so it
        // was never a meaningful gate for reading other people's records. The
        // office role is what "may read passengers across the fleet" means.
        // The deny side is covered in Tests\Feature\Security\LiveHolesTest.
        $user = $this->makeUser(['View Queues', 'View Passengers'], $world['sacco']);

        $booking = $this->makeBooking($queue, $user, $world['from'], $world['to'], 'Wanjiku');
        $this->makeBooking($otherQueue, $user, $world['from'], $world['to'], 'Otieno');

        Sanctum::actingAs($user);

        $this->getJson('/api/auth/queues/bookings/view/' . $queue->id)
            ->assertOk()
            ->assertJsonPath('queue.id', $queue->id)
            ->assertJsonCount(1, 'bookings')
            ->assertJsonPath('bookings.0.id', $booking->id)
            ->assertJsonPath('bookings.0.name', 'Wanjiku');
    }

    #[Test]
    public function the_office_cannot_complete_a_queue_at_all(): void
    {
        // Retired 2026-09-12: ending a trip is the crew's action, from the bus,
        // after every paid passenger has been marked. The route answers 410 so
        // a dashboard still calling it is told why, and the queue is untouched.
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $this->makeQueueStatus('Completed', 'Completed');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        Sanctum::actingAs($this->makeUser(['Edit Queues'], $world['sacco']));

        $this->postJson('/api/auth/queues/complete/queue', ['id' => $queue->id])
            ->assertStatus(410)
            ->assertJsonPath('error', 'Ending a trip is a crew action. The driver ends it from the bus, after every paid passenger has been marked boarded or not boarded.');

        $this->assertSame($pending->id, $queue->fresh()->queue_status_id);
    }

    #[Test]
    public function the_geofence_endpoint_returns_the_active_queue_for_the_users_vehicle(): void
    {
        $world = $this->makeWorld();
        $active = $this->makeQueueStatus('Active', 'Active');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $active, $world['owner']);
        $user = $this->makeUser(['View Queues'], $world['sacco']);
        \App\Models\VehicleUser::create([
            'user_id' => $user->id,
            'vehicle_id' => $world['vehicle']->id,
            'sacco_id' => $world['sacco']->id,
            'status' => true,
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/auth/queues/geofence')
            ->assertOk()
            ->assertJsonPath('queue.id', $queue->id)
            ->assertJsonCount(1, 'vehicles');
    }
}
