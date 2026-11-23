<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Models\Queue;
use App\Models\User;
use App\Models\VehicleUser;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Every location ping now says whether a passenger can book the bus off it.
 *
 * KDN 024V, 2026-11-22, 16:23 to 16:57: the phone pinged every few seconds,
 * was told "broadcasting" and `live: true` every time, and was offered to
 * nobody -- its trip had ended and the app kept naming it. Twenty passenger
 * searches on its route came back empty while the driver's screen said live.
 */
final class PingSaysWhetherBookableTest extends QueueTestCase
{
    private const PING = '/api/v1/auth/book_a_ride/location';

    private function driver(array $world): User
    {
        $d = $this->makeUser([], $world['sacco']);
        VehicleUser::create([
            'user_id' => $d->id, 'vehicle_id' => $world['vehicle']->id,
            'sacco_id' => $world['sacco']->id, 'status' => true, 'start_date' => now(),
        ]);

        return $d;
    }

    #[Test]
    public function live_on_a_route_is_bookable(): void
    {
        $this->makeQueueStatus('Active', 'Active');
        $world = $this->makeWorld();
        Sanctum::actingAs($this->driver($world));

        $this->postJson(self::PING, ['latitude' => -1.20, 'longitude' => 36.93, 'route_id' => $world['route']->id])
            ->assertStatus(202)
            ->assertJsonPath('live', true)
            ->assertJsonPath('bookable', true)
            ->assertJsonPath('reason', null)
            ->assertJsonPath('message', null);
    }

    #[Test]
    public function on_the_map_with_no_route_is_not(): void
    {
        $world = $this->makeWorld();
        Sanctum::actingAs($this->driver($world));

        $this->postJson(self::PING, ['latitude' => -1.20, 'longitude' => 36.93])
            ->assertStatus(202)
            ->assertJsonPath('live', true)
            ->assertJsonPath('bookable', false)
            ->assertJsonPath('reason', 'no_route');
    }

    #[Test]
    public function naming_a_trip_that_has_ended_says_so(): void
    {
        // What the app does: it broadcasts against the trip it was given and
        // stops sending the route, so it keeps naming the trip after it ends.
        $world = $this->makeWorld();
        $run = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'],
            $this->makeQueueStatus('Completed', 'Completed'), $world['owner'], 'LIVE', Queue::KIND_LIVE);
        Sanctum::actingAs($this->driver($world));

        $this->postJson(self::PING, ['latitude' => -1.20, 'longitude' => 36.93, 'queue_id' => $run->id])
            ->assertStatus(202)
            ->assertJsonPath('bookable', false)
            ->assertJsonPath('reason', 'trip_ended');
    }

    #[Test]
    public function an_open_run_on_the_bus_is_bookable_whatever_the_ping_names(): void
    {
        // The passenger list lists the bus off any open run of its, so the
        // driver must be told the same -- not "trip ended" from a second
        // phone still naming yesterday's run.
        $world = $this->makeWorld();
        $old = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'],
            $this->makeQueueStatus('Completed', 'Completed'), $world['owner'], 'LIVE', Queue::KIND_LIVE);
        $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'],
            $this->makeQueueStatus('Active', 'Active'), $world['owner'], 'LIVE', Queue::KIND_LIVE);
        Sanctum::actingAs($this->driver($world));

        $this->postJson(self::PING, ['latitude' => -1.20, 'longitude' => 36.93, 'queue_id' => $old->id])
            ->assertStatus(202)->assertJsonPath('bookable', true)->assertJsonPath('reason', null);
    }

    #[Test]
    public function a_run_the_passenger_list_would_not_show_is_not_bookable(): void
    {
        $world = $this->makeWorld();
        $suspended = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'],
            $this->makeQueueStatus('Suspended', 'Suspended'), $world['owner'], 'LIVE', Queue::KIND_LIVE);
        Sanctum::actingAs($this->driver($world));

        $this->postJson(self::PING, ['latitude' => -1.20, 'longitude' => 36.93, 'queue_id' => $suspended->id])
            ->assertStatus(202)->assertJsonPath('bookable', false)->assertJsonPath('reason', 'trip_ended');
    }

    #[Test]
    public function naming_a_stage_queue_says_so(): void
    {
        $world = $this->makeWorld();
        $stage = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'],
            $this->makeQueueStatus('Active', 'Active'), $world['owner'], 'QN-1', Queue::KIND_STAGE);
        Sanctum::actingAs($this->driver($world));

        $this->postJson(self::PING, ['latitude' => -1.20, 'longitude' => 36.93, 'queue_id' => $stage->id])
            ->assertStatus(202)
            ->assertJsonPath('bookable', false)
            ->assertJsonPath('reason', 'stage_queue');
    }

    #[Test]
    public function an_old_fix_is_not_bookable_even_on_a_route(): void
    {
        $this->makeQueueStatus('Active', 'Active');
        $world = $this->makeWorld();
        Sanctum::actingAs($this->driver($world));

        $this->postJson(self::PING, [
            'latitude' => -1.20, 'longitude' => 36.93, 'route_id' => $world['route']->id,
            'fixed_at' => now()->subMinutes(10)->toIso8601String(),
        ])
            ->assertStatus(202)
            ->assertJsonPath('live', false)
            ->assertJsonPath('bookable', false)
            ->assertJsonPath('reason', 'stale_fix');
    }
}
