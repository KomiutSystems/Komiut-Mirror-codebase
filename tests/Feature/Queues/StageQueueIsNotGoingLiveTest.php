<?php

declare(strict_types=1);

namespace Tests\Feature\Queues;

use App\Enums\UserType;
use App\Models\Booking;
use App\Models\Queue;
use App\Models\User;
use App\Models\VehicleUser;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;

/**
 * Joining a stage's queue and going live on a route are two separate acts.
 *
 * Decided 2026-10-06. Join Queue: the driver picks any stage of their SACCO;
 * no route, and the bus is NOT bookable. Go live: the driver picks the route
 * A - B they are running, and only that offers the bus to passengers. Neither
 * creates, reuses nor blocks the other.
 *
 * Before this, going live reused whatever queue the bus had open and a stage
 * queue carried a route the app derived from the stage tapped -- KDN 458N,
 * live on Nairobi CBD - Thika, showed up queued at Ambassadeur on
 * Ambassadeur - Alsops.
 */
final class StageQueueIsNotGoingLiveTest extends QueueTestCase
{
    private const PING = '/api/v1/auth/book_a_ride/location';

    /** @return array<string, mixed> */
    private function shift(): array
    {
        $world = $this->makeWorld();
        $this->makeQueueStatus('Pending', 'Pending');
        $this->makeQueueStatus('Active', 'Active');
        $this->makeQueueStatus('Completed', 'Completed');
        $this->makeQueueStatus('Cancelled', 'Cancelled');

        $driver = $this->makeUser(['Edit Queues'], $world['sacco']);
        $driver->forceFill(['type' => UserType::Driver])->save();
        VehicleUser::create([
            'user_id' => $driver->id, 'vehicle_id' => $world['vehicle']->id,
            'sacco_id' => $world['sacco']->id, 'status' => true, 'start_date' => now(),
        ]);

        // The way back, priced and staged like the way out.
        $back = $this->makeRoute($world['to'], $world['from'], $world['sacco']);
        $this->makeSaccoRoute($world['sacco'], $back, $world['owner'], 200);
        $this->makeRouteStage($back, $world['to'], 0);
        $this->makeRouteStage($back, $world['from'], 40);

        return $world + ['driver' => $driver, 'back' => $back];
    }

    /** @param array<string, mixed> $world */
    private function joinStage(array $world): Queue
    {
        $id = $this->postJson('/api/v1/auth/queues/join', ['terminus_id' => $world['terminus']->id])
            ->assertStatus(201)->json('queue.id');

        return Queue::withoutGlobalScopes()->findOrFail($id);
    }

    /** @param array<string, mixed> $world */
    private function listed(array $world): array
    {
        return $this->getJson('/api/v1/auth/book_a_ride/queues')->assertOk()->json('queues');
    }

    #[Test]
    public function a_bus_in_a_stage_line_is_not_on_offer_even_while_broadcasting(): void
    {
        $world = $this->shift();
        Sanctum::actingAs($world['driver']);
        $stage = $this->joinStage($world);

        // Broadcasting with no route chosen: on the map, offered to nobody.
        $this->postJson(self::PING, ['latitude' => -1.28, 'longitude' => 36.82])
            ->assertStatus(202)
            ->assertJsonPath('queue_id', null);

        // Even naming the stage queue does not make it a trip.
        $this->postJson(self::PING, ['latitude' => -1.28, 'longitude' => 36.82, 'queue_id' => $stage->id])
            ->assertStatus(202)
            ->assertJsonPath('queue_id', null);

        Sanctum::actingAs($this->makeUser());
        $this->assertSame([], $this->listed($world));
        $this->assertSame(1, Queue::withoutGlobalScopes()->count(), 'no run was made from the stage queue');
    }

    #[Test]
    public function going_live_from_a_stage_line_makes_its_own_run_on_the_chosen_route(): void
    {
        $world = $this->shift();
        Sanctum::actingAs($world['driver']);
        $stage = $this->joinStage($world);

        // Waiting at the Nairobi stage, live on the way BACK -- whatever the
        // stage might suggest, the route is the driver's choice.
        $runId = $this->postJson(self::PING, ['latitude' => -1.28, 'longitude' => 36.82, 'route_id' => $world['back']->id])
            ->assertStatus(202)->json('queue_id');

        $this->assertNotNull($runId);
        $this->assertNotSame($stage->id, $runId);
        $run = Queue::withoutGlobalScopes()->findOrFail($runId);
        $this->assertSame(Queue::KIND_LIVE, $run->kind);
        $this->assertSame($world['back']->id, (int) $run->route_id);

        // The stage queue is exactly where it was: same line, same place, no route.
        $stage->refresh();
        $this->assertSame('Pending', $stage->queue_status->status);
        $this->assertSame(1, (int) $stage->position);
        $this->assertNull($stage->route_id);

        // driver/trip says the run, not the line, so the app pings against it.
        $this->getJson('/api/v1/auth/driver/trip')
            ->assertOk()
            ->assertJsonPath('trip.queue_id', $runId)
            ->assertJsonPath('trip.kind', Queue::KIND_LIVE)
            ->assertJsonPath('stage.queue_id', $stage->id);

        // Passengers see the run, and only the run.
        Sanctum::actingAs($this->makeUser());
        $rows = $this->listed($world);
        $this->assertCount(1, $rows);
        $this->assertSame($runId, $rows[0]['id']);
    }

    #[Test]
    public function a_waiting_stage_queue_is_not_the_drivers_trip(): void
    {
        // The driver app sends a ping's queue_id from driver/trip and drops
        // the route it went live on whenever there is one. A waiting stage
        // queue reported there would swallow every go-live from a stage.
        $world = $this->shift();
        Sanctum::actingAs($world['driver']);
        $stage = $this->joinStage($world);

        $this->getJson('/api/v1/auth/driver/trip')
            ->assertOk()
            ->assertJsonPath('trip', null)
            ->assertJsonPath('stage.queue_id', $stage->id)
            ->assertJsonPath('stage.kind', Queue::KIND_STAGE);

        // Departed without going live (an older app): that IS a trip.
        $this->postJson('/api/v1/auth/trips/start')->assertOk();
        $this->getJson('/api/v1/auth/driver/trip')
            ->assertOk()
            ->assertJsonPath('trip.queue_id', $stage->id);
    }

    #[Test]
    public function a_stage_queue_cannot_be_booked(): void
    {
        $world = $this->shift();
        Sanctum::actingAs($world['driver']);
        $stage = $this->joinStage($world);

        Sanctum::actingAs($this->makeUser());
        $this->postJson('/api/v1/auth/book_a_ride/booking/add', [
            'id' => $stage->id, 'seats' => (string) $world['arrangements'][0]->id,
            'name' => 'Tom', 'phone' => '0722123456',
        ])->assertStatus(422);

        $this->assertSame(0, Booking::withoutGlobalScopes()->count());
    }

    #[Test]
    public function changing_route_ends_an_empty_run_and_starts_one_on_the_new_route(): void
    {
        $world = $this->shift();
        Sanctum::actingAs($world['driver']);

        $out = $this->postJson(self::PING, ['latitude' => -1.0, 'longitude' => 37.0, 'route_id' => $world['route']->id])
            ->assertStatus(202)->json('queue_id');

        // Same route: the same run, every ping.
        $this->postJson(self::PING, ['latitude' => -1.0, 'longitude' => 37.0, 'queue_id' => $out])
            ->assertStatus(202)->assertJsonPath('queue_id', $out);

        // At Thika, live on the way back.
        $back = $this->postJson(self::PING, ['latitude' => -1.0, 'longitude' => 37.0, 'route_id' => $world['back']->id])
            ->assertStatus(202)->json('queue_id');

        $this->assertNotSame($out, $back);
        $this->assertSame('Completed', Queue::withoutGlobalScopes()->find($out)->queue_status->status);
        $this->assertSame($world['back']->id, (int) Queue::withoutGlobalScopes()->find($back)->route_id);
    }

    #[Test]
    public function changing_route_with_passengers_still_waiting_keeps_the_run(): void
    {
        $world = $this->shift();
        Sanctum::actingAs($world['driver']);

        $out = $this->postJson(self::PING, ['latitude' => -1.0, 'longitude' => 37.0, 'route_id' => $world['route']->id])
            ->assertStatus(202)->json('queue_id');
        $this->makeBooking(Queue::withoutGlobalScopes()->find($out), $this->makeUser(), $world['from'], $world['to'], 'Wanjiku');

        // Ending it would cancel and refund someone waiting at a stop for this bus.
        $this->postJson(self::PING, ['latitude' => -1.0, 'longitude' => 37.0, 'route_id' => $world['back']->id])
            ->assertStatus(202)->assertJsonPath('queue_id', $out);

        $this->assertSame('Active', Queue::withoutGlobalScopes()->find($out)->queue_status->status);
        $this->assertSame(1, Queue::withoutGlobalScopes()->live()->count());
    }

    #[Test]
    public function ending_the_trip_ends_the_run_and_the_departed_stage_queue_as_one_trip(): void
    {
        $world = $this->shift();
        Sanctum::actingAs($world['driver']);
        $stage = $this->joinStage($world);
        $this->postJson('/api/v1/auth/trips/start')->assertOk();
        $runId = $this->postJson(self::PING, ['latitude' => -1.28, 'longitude' => 36.82, 'route_id' => $world['route']->id])
            ->assertStatus(202)->json('queue_id');

        $this->postJson('/api/v1/auth/driver/trip/end')
            ->assertOk()
            ->assertJsonPath('trip.queue_id', $runId);

        $run = Queue::withoutGlobalScopes()->find($runId);
        $stage->refresh();
        $this->assertSame('Completed', $run->queue_status->status);
        $this->assertSame('Completed', $stage->queue_status->status);
        $this->assertEquals($run->end_time, $stage->end_time);

        // Two rows, one journey: counted once.
        $this->assertSame(1, Queue::withoutGlobalScopes()->trips()->count());
        $this->assertSame([$runId], Queue::withoutGlobalScopes()->trips()->pluck('id')->map(fn ($id) => (int) $id)->all());

        $this->getJson('/api/v1/auth/driver/trips')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('trips.0.id', $runId);
    }

    #[Test]
    public function ending_a_live_run_leaves_a_bus_still_waiting_in_a_line_where_it_is(): void
    {
        $world = $this->shift();
        Sanctum::actingAs($world['driver']);
        $stage = $this->joinStage($world);
        $this->postJson(self::PING, ['latitude' => -1.28, 'longitude' => 36.82, 'route_id' => $world['route']->id])
            ->assertStatus(202);

        $this->postJson('/api/v1/auth/driver/trip/end')->assertOk();

        $stage->refresh();
        $this->assertSame('Pending', $stage->queue_status->status, 'still waiting at the stage');
        $this->assertSame(1, (int) $stage->position);
    }

    #[Test]
    public function a_departed_stage_queue_with_no_run_is_still_a_trip(): void
    {
        // An app that departs the stage without going live: unchanged.
        $world = $this->shift();
        Sanctum::actingAs($world['driver']);
        $stage = $this->joinStage($world);
        $this->postJson('/api/v1/auth/trips/start')->assertOk();

        $this->postJson('/api/v1/auth/driver/trip/end')
            ->assertOk()
            ->assertJsonPath('trip.queue_id', $stage->id);

        $this->assertSame([$stage->id], Queue::withoutGlobalScopes()->trips()->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    #[Test]
    public function the_dashboard_queue_list_and_the_geofence_show_the_line_not_the_run(): void
    {
        $world = $this->shift();
        Sanctum::actingAs($world['driver']);
        $stage = $this->joinStage($world);
        $runId = $this->postJson(self::PING, ['latitude' => -1.28, 'longitude' => 36.82, 'route_id' => $world['route']->id])
            ->assertStatus(202)->json('queue_id');

        $this->getJson('/api/v1/auth/queues/geofence')
            ->assertOk()
            ->assertJsonPath('queue.id', $stage->id);

        $dispatcher = $this->makeUser(['View Queues'], $world['sacco']);
        Sanctum::actingAs($dispatcher);
        $ids = collect($this->getJson('/api/v1/auth/queues')->assertOk()->json('queues'))->pluck('id')->all();
        $this->assertSame([$stage->id], $ids);

        $ids = collect($this->getJson('/api/v1/auth/queues?kind=live')->assertOk()->json('queues'))->pluck('id')->all();
        $this->assertSame([$runId], $ids);
    }
}
