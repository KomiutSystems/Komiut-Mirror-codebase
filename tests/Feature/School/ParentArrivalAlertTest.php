<?php

declare(strict_types=1);

namespace Tests\Feature\School;

use App\Enums\NotificationType;
use App\Models\Queue;
use App\Models\Sacco;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleFollower;
use App\Models\VehicleLocation;
use App\Models\VehicleUser;
use App\Notifications\PlatformNotification;
use App\Services\Booking\ArrivalAlerts;
use App\Services\School\SchoolBuses;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * "Your school bus is nearly there": a parent following a school bus is pushed
 * once per run, the first time the bus comes within
 * booking.arrival_alert_metres of their stop (docs/school-bus-tracking.md).
 */
final class ParentArrivalAlertTest extends QueueTestCase
{
    private const PING = '/api/v1/auth/book_a_ride/location';

    // A parent's stop, and points roughly 1 km and 100 m north of it.
    private const STOP = [-1.2833, 36.8167];

    private const FAR = [-1.2743, 36.8167];

    private const NEAR = [-1.2824, 36.8167];

    /** @return array{0: array, 1: Queue, 2: User} */
    private function liveSchoolRun(bool $school = true): array
    {
        $world = $this->makeWorld();
        if ($school) {
            $world['sacco']->forceFill(['kind' => Sacco::KIND_SCHOOL])->save();
        }
        $run = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'],
            $this->makeQueueStatus('Active '.$this->nextSequence(), 'Active'), $world['owner'], 'LIVE');
        $driver = $this->makeUser([], $world['sacco']);
        VehicleUser::create([
            'user_id' => $driver->id, 'vehicle_id' => $world['vehicle']->id,
            'sacco_id' => $world['sacco']->id, 'status' => true, 'start_date' => now(),
        ]);

        return [$world, $run, $driver];
    }

    /** @param  array{0: float, 1: float}|null  $pin */
    private function follower(Vehicle $bus, ?array $pin, ?int $placeId = null): User
    {
        $parent = $this->makeUser();
        VehicleFollower::create([
            'user_id' => $parent->id, 'vehicle_id' => $bus->id, 'stop_place_id' => $placeId,
            'stop_latitude' => $pin[0] ?? null, 'stop_longitude' => $pin[1] ?? null,
        ]);

        return $parent;
    }

    private function alerts(User $user): int
    {
        return Notification::sent($user, PlatformNotification::class)
            ->filter(fn (PlatformNotification $n) => $n->type === NotificationType::BusApproaching)
            ->count();
    }

    private function ping(User $driver, Queue $run, array $at): void
    {
        Sanctum::actingAs($driver);
        $this->postJson(self::PING, ['latitude' => $at[0], 'longitude' => $at[1], 'queue_id' => $run->id])->assertStatus(202);
    }

    #[Test]
    public function a_follower_is_told_once_per_run_when_the_bus_nears_their_stop(): void
    {
        Notification::fake();
        [$world, $run, $driver] = $this->liveSchoolRun();
        $parent = $this->follower($world['vehicle'], self::STOP);

        $this->ping($driver, $run, self::FAR);
        $this->assertSame(0, $this->alerts($parent), 'a kilometre away is not approaching');

        $this->ping($driver, $run, self::NEAR);
        $this->assertSame(1, $this->alerts($parent));
        $row = VehicleFollower::where('user_id', $parent->id)->first();
        $this->assertSame($run->id, (int) $row->last_alerted_queue_id);
        $this->assertNotNull($row->last_alerted_at);

        $this->ping($driver, $run, self::NEAR);
        $this->assertSame(1, $this->alerts($parent), 'once per run, however many pings follow');

        // The message names the bus and the time to the stop, and nothing else.
        // No speed yet, so city speed: 100 m is well inside a minute.
        $sent = Notification::sent($parent, PlatformNotification::class)->first();
        $this->assertSame('Your school bus '.$world['vehicle']->plate.' is about 1 minute from your stop.', $sent->message);
        $this->assertSame('Your school bus is 1 minute away', $sent->title);
        $this->assertSame((string) $run->id, $sent->referenceId);
    }

    #[Test]
    public function the_alert_comes_about_two_minutes_out_at_the_buses_own_speed(): void
    {
        Notification::fake();
        [$world, $run, $driver] = $this->liveSchoolRun();
        $parent = $this->follower($world['vehicle'], self::STOP);

        // Two fixes 30 s apart, 250 m apart: 30 km/h. 2 minutes at 30 km/h is
        // 1 km, so a bus 1.5 km out is not yet "approaching"...
        Sanctum::actingAs($driver);
        $this->postJson(self::PING, ['latitude' => -1.2968, 'longitude' => 36.8167, 'queue_id' => $run->id, 'fixed_at' => now()->subSeconds(30)->toIso8601String()])->assertStatus(202);
        $this->postJson(self::PING, ['latitude' => -1.2946, 'longitude' => 36.8167, 'queue_id' => $run->id, 'fixed_at' => now()->toIso8601String()])->assertStatus(202);
        $this->assertEqualsWithDelta(30.0, VehicleLocation::where('vehicle_id', $world['vehicle']->id)->value('speed_kmh'), 4.0);
        $this->assertSame(0, $this->alerts($parent));

        // ...and one about 950 m out is: "about 2 minutes".
        $this->travel(30)->seconds();
        $this->postJson(self::PING, ['latitude' => -1.2918, 'longitude' => 36.8167, 'queue_id' => $run->id, 'fixed_at' => now()->toIso8601String()])->assertStatus(202);
        $this->assertSame(1, $this->alerts($parent));
        $sent = Notification::sent($parent, PlatformNotification::class)->first();
        $this->assertSame('Your school bus '.$world['vehicle']->plate.' is about 2 minutes from your stop.', $sent->message);
    }

    #[Test]
    public function a_gps_jump_does_not_become_a_speed(): void
    {
        [$world, $run, $driver] = $this->liveSchoolRun();
        Sanctum::actingAs($driver);
        $this->postJson(self::PING, ['latitude' => -1.2968, 'longitude' => 36.8167, 'queue_id' => $run->id, 'fixed_at' => now()->subSeconds(10)->toIso8601String()])->assertStatus(202);
        // 10 km in 10 seconds.
        $this->postJson(self::PING, ['latitude' => -1.2068, 'longitude' => 36.8167, 'queue_id' => $run->id, 'fixed_at' => now()->toIso8601String()])->assertStatus(202);

        $this->assertNull(VehicleLocation::where('vehicle_id', $world['vehicle']->id)->value('speed_kmh'));
    }

    #[Test]
    public function the_next_run_alerts_again(): void
    {
        Notification::fake();
        [$world, $run, $driver] = $this->liveSchoolRun();
        $parent = $this->follower($world['vehicle'], self::STOP);

        $this->ping($driver, $run, self::NEAR);
        $this->assertSame(1, $this->alerts($parent));

        // The afternoon run: a new trip on the same bus.
        $run->forceFill(['queue_status_id' => $this->makeQueueStatus('Completed', 'Completed')->id])->save();
        $afternoon = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'],
            $this->makeQueueStatus('Active '.$this->nextSequence(), 'Active'), $world['owner'], 'LIVE');

        $this->ping($driver, $afternoon, self::NEAR);
        $this->assertSame(2, $this->alerts($parent));
    }

    #[Test]
    public function only_followers_whose_stop_is_within_the_radius_are_told(): void
    {
        Notification::fake();
        [$world, $run, $driver] = $this->liveSchoolRun();
        $near = $this->follower($world['vehicle'], self::STOP);
        $far = $this->follower($world['vehicle'], self::FAR);
        $noStop = $this->follower($world['vehicle'], null);
        // A stop chosen from the route's places rather than a pin.
        $world['from']->forceFill(['latitude' => self::STOP[0], 'longitude' => self::STOP[1]])->save();
        $byPlace = $this->follower($world['vehicle'], null, (int) $world['from']->id);
        // Following a different school bus.
        [$otherWorld] = $this->liveSchoolRun();
        $elsewhere = $this->follower($otherWorld['vehicle'], self::STOP);

        $this->ping($driver, $run, self::NEAR);

        $this->assertSame(1, $this->alerts($near));
        $this->assertSame(1, $this->alerts($byPlace));
        $this->assertSame(0, $this->alerts($far));
        $this->assertSame(0, $this->alerts($noStop));
        $this->assertSame(0, $this->alerts($elsewhere));
    }

    #[Test]
    public function two_pings_racing_alert_once(): void
    {
        Notification::fake();
        [$world, $run] = $this->liveSchoolRun();
        $parent = $this->follower($world['vehicle'], self::STOP);

        // The second ping read the follower before the first claimed it: the
        // guarded UPDATE is what keeps it to one push.
        VehicleFollower::where('user_id', $parent->id)->update(['last_alerted_queue_id' => $run->id, 'last_alerted_at' => now()]);
        app(ArrivalAlerts::class)->check($run, self::NEAR[0], self::NEAR[1]);

        $this->assertSame(0, $this->alerts($parent));
    }

    #[Test]
    public function a_matatu_ping_never_reads_followers(): void
    {
        Notification::fake();
        [$world, $run, $driver] = $this->liveSchoolRun(school: false);
        // Even with a (stray) follower row, a matatu is not looked up.
        $this->follower($world['vehicle'], self::STOP);
        SchoolBuses::isSchoolBus((int) $world['vehicle']->id);

        $followerQueries = 0;
        DB::listen(function ($query) use (&$followerQueries): void {
            if (str_contains($query->sql, 'vehicle_followers')) {
                $followerQueries++;
            }
        });

        $this->ping($driver, $run, self::NEAR);
        app(ArrivalAlerts::class)->check($run, self::NEAR[0], self::NEAR[1]);

        $this->assertSame(0, $followerQueries);
    }
}
