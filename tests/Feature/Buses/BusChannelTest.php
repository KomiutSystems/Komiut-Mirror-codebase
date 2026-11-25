<?php

declare(strict_types=1);

namespace Tests\Feature\Buses;

use App\Brands\BrandRegistry;
use App\Events\VehicleMoved;
use App\Models\Queue;
use App\Models\Sacco;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLocation;
use App\Models\VehicleUser;
use App\Providers\BroadcastServiceProvider;
use App\Services\Buses\SavedBuses;
use App\Services\Location\VehicleLocationService;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A saved bus's live position: the bus.{key} channel, which admits exactly
 * whom buses/live answers for that key; the one VehicleMoved broadcast that
 * reaches it, only while someone is watching; and the smoothed speed the app
 * works out its own arrival estimate from (docs/school-bus-tracking.md,
 * "Saved buses").
 *
 * Channel callbacks are exercised through the real /broadcasting/auth route
 * against a real (locally signing) broadcaster, the way BroadcastAuthTest
 * does it -- under the suite's null broadcaster no callback would ever run.
 */
final class BusChannelTest extends QueueTestCase
{
    private const AUTH = '/broadcasting/auth';

    private const PING = '/api/v1/auth/book_a_ride/location';

    private const OTHER_APP_KEY = 'other-app-key';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb' => [
                'driver' => 'reverb',
                'key' => 'testing-reverb-key',
                'secret' => 'testing-reverb-secret',
                'app_id' => 'testing',
                'options' => ['host' => 'localhost', 'port' => 443, 'scheme' => 'https', 'useTLS' => true],
                'client_options' => [],
            ],
        ]);
        // See BroadcastAuthTest::setUp: drop the cached null driver, then
        // re-register so routes/channels.php binds to the real one.
        $this->app->forgetInstance(BroadcastFactory::class);
        $this->app->forgetInstance(BroadcastManager::class);
        $this->app->register(BroadcastServiceProvider::class, true);

        config(['brands.other' => [
            'name' => 'Other',
            'connection' => config('database.default'),
            'hosts' => [],
            'app_key' => self::OTHER_APP_KEY,
            'features' => [],
        ]]);
        $this->app->forgetInstance(BrandRegistry::class);
    }

    private function subscribe(User $user, string $channel, bool $otherApp = false): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->postJson(self::AUTH, ['socket_id' => '1234.5678', 'channel_name' => $channel],
            $otherApp ? ['X-App-Key' => self::OTHER_APP_KEY] : []);
    }

    private function channelOf(Vehicle|int $bus): string
    {
        return 'private-bus.'.SavedBuses::key($bus instanceof Vehicle ? $bus->id : $bus);
    }

    private function watch(Vehicle $bus): void
    {
        SavedBuses::markWatched($bus->id);
    }

    /** @return array<int, string> */
    private function channelNames(VehicleMoved $event): array
    {
        return array_map(fn (PrivateChannel $c) => $c->name, $event->broadcastOn());
    }

    /** @return array{0: array, 1: Queue, 2: User} */
    private function liveRunWithDriver(bool $school = false): array
    {
        $world = $this->makeWorld();
        if ($school) {
            $world['sacco']->forceFill(['kind' => Sacco::KIND_SCHOOL])->save();
        }
        $run = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'],
            $this->makeQueueStatus('Active '.$this->nextSequence(), 'Active'), $world['owner']);
        $driver = $this->makeUser([], $world['sacco']);
        VehicleUser::create([
            'user_id' => $driver->id, 'vehicle_id' => $world['vehicle']->id,
            'sacco_id' => $world['sacco']->id, 'status' => true, 'start_date' => now(),
        ]);

        return [$world, $run, $driver];
    }

    // ---------------------------------------------------------------- channel

    #[Test]
    public function any_signed_in_user_with_the_key_may_listen_to_a_bus_in_service(): void
    {
        $matatu = $this->makeWorld()['vehicle'];
        $school = $this->makeWorld();
        $school['sacco']->forceFill(['kind' => Sacco::KIND_SCHOOL])->save();
        // A stranger, and someone in another SACCO altogether.
        foreach ([$this->makeUser(), $this->makeUser([], $this->makeSacco())] as $user) {
            foreach ([$matatu, $school['vehicle']] as $bus) {
                $this->subscribe($user, $this->channelOf($bus))->assertOk()->assertJsonStructure(['auth']);
            }
        }
    }

    #[Test]
    public function the_bare_id_or_a_guessed_key_admits_nobody(): void
    {
        $school = $this->makeWorld();
        $school['sacco']->forceFill(['kind' => Sacco::KIND_SCHOOL])->save();
        $id = $school['vehicle']->id;
        $user = $this->makeUser();

        $this->subscribe($user, 'private-bus.'.$id)->assertForbidden();
        $this->subscribe($user, 'private-bus.'.$id.'-'.str_repeat('0', 20))->assertForbidden();
        // This bus's signature on the next id along.
        $this->subscribe($user, 'private-bus.'.($id + 1).substr(SavedBuses::key($id), strlen((string) $id)))->assertForbidden();
        $this->subscribe($user, $this->channelOf($id))->assertOk();
    }

    #[Test]
    public function the_channel_keeps_the_same_brand_wall_as_buses_live(): void
    {
        $ours = $this->makeWorld()['vehicle'];
        $other = $this->makeWorld();
        $other['sacco']->forceFill(['brand' => 'other'])->save();
        $other['vehicle']->forceFill(['brand' => 'other'])->save();
        $school = $this->makeWorld();
        $school['sacco']->forceFill(['kind' => Sacco::KIND_SCHOOL, 'brand' => 'other'])->save();
        $school['vehicle']->forceFill(['brand' => 'other'])->save();
        $user = $this->makeUser();

        // From this app (its host): our matatu and any school bus; not theirs.
        $this->subscribe($user, $this->channelOf($ours))->assertOk();
        $this->subscribe($user, $this->channelOf($school['vehicle']))->assertOk();
        $this->subscribe($user, $this->channelOf($other['vehicle']))->assertForbidden();

        // From the other app (its X-App-Key): the other way round.
        $this->subscribe($user, $this->channelOf($other['vehicle']), otherApp: true)->assertOk();
        $this->subscribe($user, $this->channelOf($school['vehicle']), otherApp: true)->assertOk();
        $this->subscribe($user, $this->channelOf($ours), otherApp: true)->assertForbidden();

        // ...and live says the same thing about the same keys.
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/auth/buses/live?keys='.SavedBuses::key($other['vehicle']->id))
            ->assertOk()->assertJsonPath('missing', [SavedBuses::key($other['vehicle']->id)]);

        // A request that names no app at all admits no matatu, only school buses.
        config(['brands.testing.hosts' => []]);
        $this->app->forgetInstance(BrandRegistry::class);
        $this->subscribe($user, $this->channelOf($ours))->assertForbidden();
        $this->subscribe($user, $this->channelOf($school['vehicle']))->assertOk();
    }

    #[Test]
    public function an_unknown_or_out_of_service_bus_admits_nobody(): void
    {
        $user = $this->makeUser();
        $this->subscribe($user, $this->channelOf(99999999))->assertForbidden();

        $off = $this->makeWorld()['vehicle'];
        $off->forceFill(['status' => false])->save();
        $this->subscribe($user, $this->channelOf($off))->assertForbidden();

        $offOperator = $this->makeWorld();
        $offOperator['sacco']->forceFill(['status' => 0])->save();
        $this->subscribe($user, $this->channelOf($offOperator['vehicle']))->assertForbidden();
    }

    #[Test]
    public function subscribing_is_capped_per_user_per_minute(): void
    {
        $bus = $this->makeWorld()['vehicle'];
        $user = $this->makeUser();

        for ($i = 0; $i < SavedBuses::CHANNEL_AUTHS_PER_MINUTE; $i++) {
            $this->subscribe($user, $this->channelOf($bus))->assertOk();
        }
        $this->subscribe($user, $this->channelOf($bus))->assertForbidden();
        // Another user is not held up by the first one's ceiling.
        $this->subscribe($this->makeUser(), $this->channelOf($bus))->assertOk();
    }

    #[Test]
    public function the_old_school_bus_channel_admits_nobody(): void
    {
        $school = $this->makeWorld();
        $school['sacco']->forceFill(['kind' => Sacco::KIND_SCHOOL])->save();

        // No such channel any more: even the bus's own owner is refused.
        $this->subscribe($school['owner'], 'private-school-bus.'.$school['vehicle']->id)->assertForbidden();
    }

    // ---------------------------------------------------------------- event

    #[Test]
    public function a_fresh_ping_of_a_watched_bus_goes_to_the_trip_and_the_bus_in_one_broadcast(): void
    {
        foreach ([false, true] as $school) {
            [$world, $run] = $this->liveRunWithDriver($school);
            $this->watch($world['vehicle']);
            $event = new VehicleMoved($this->pingFrom($world['vehicle'], $run), $world['vehicle']->plate);

            $this->assertSame(['private-trip.'.$run->id, $this->channelOf($world['vehicle'])], $this->channelNames($event));
            $this->assertSame('vehicle.moved', $event->broadcastAs());
            $this->assertSame(
                ['vehicle_id', 'queue_id', 'plate', 'latitude', 'longitude', 'heading', 'recorded_at'],
                array_keys($event->broadcastWith()),
            );
        }
    }

    #[Test]
    public function a_bus_nobody_is_watching_costs_no_bus_broadcast(): void
    {
        [$world, $run] = $this->liveRunWithDriver();
        $bus = $world['vehicle'];

        // On a run: the trip still hears it; no bus channel.
        $this->assertSame(['private-trip.'.$run->id], $this->channelNames(new VehicleMoved($this->pingFrom($bus, $run))));
        // No run, nobody watching: no channel at all, so no call to Reverb.
        $this->assertSame([], $this->channelNames(new VehicleMoved($this->pingFrom($bus))));

        // Subscribing marks it watched...
        $this->subscribe($this->makeUser(), $this->channelOf($bus))->assertOk();
        $this->assertSame([$this->channelOf($bus)], $this->channelNames(new VehicleMoved($this->pingFrom($bus))));

        // ...for a while, renewed by each ping broadcast to it.
        $this->travel(SavedBuses::WATCH_SECONDS - 60)->seconds();
        $this->assertSame([$this->channelOf($bus)], $this->channelNames(new VehicleMoved($this->pingFrom($bus))));
        $this->travel(SavedBuses::WATCH_SECONDS - 60)->seconds();
        $this->assertSame([$this->channelOf($bus)], $this->channelNames(new VehicleMoved($this->pingFrom($bus))));

        // Silent past the window: forgotten until someone subscribes again.
        $this->travel(SavedBuses::WATCH_SECONDS + 60)->seconds();
        Cache::store('array')->get('bus-watched:'.$bus->id); // evicts, as Redis would by itself
        $this->assertSame([], $this->channelNames(new VehicleMoved($this->pingFrom($bus))));
    }

    #[Test]
    public function a_bus_with_no_run_still_reaches_its_channel_while_watched(): void
    {
        $bus = $this->makeWorld()['vehicle'];
        $this->watch($bus);

        $this->assertSame([$this->channelOf($bus)], $this->channelNames(new VehicleMoved($this->pingFrom($bus))));
    }

    #[Test]
    public function a_stale_fix_is_not_sent_to_the_bus_channel(): void
    {
        [$world, $run] = $this->liveRunWithDriver();
        $this->watch($world['vehicle']);
        $stale = $this->pingFrom($world['vehicle'], $run, ageSeconds: VehicleLocationService::FRESH_SECONDS + 30);

        $this->assertSame(['private-trip.'.$run->id], $this->channelNames(new VehicleMoved($stale)));
    }

    #[Test]
    public function a_job_queued_by_either_release_survives_the_other(): void
    {
        $bus = $this->makeWorld()['vehicle'];
        $this->watch($bus);
        $event = new VehicleMoved($this->pingFrom($bus), $bus->plate);

        // The previous release's payload names toFollowers and not fresh: a
        // worker on this release must still broadcast it, not throw.
        $old = $event->__serialize();
        unset($old['fresh']);
        $old['toFollowers'] = true;
        $fromOld = (new \ReflectionClass(VehicleMoved::class))->newInstanceWithoutConstructor();
        $fromOld->__unserialize($old);
        $this->assertSame([], $this->channelNames($fromOld));

        // This release's payload still carries toFollowers (false), which the
        // previous release's broadcastOn reads.
        $this->assertArrayHasKey('toFollowers', $event->__serialize());
        $this->assertFalse($event->__serialize()['toFollowers']);
    }

    #[Test]
    public function the_driver_ping_dispatches_the_event_for_the_bus_channel(): void
    {
        Event::fake([VehicleMoved::class]);
        [$world, , $driver] = $this->liveRunWithDriver(school: true);
        $this->watch($world['vehicle']);

        Sanctum::actingAs($driver);
        $ping = $this->postJson(self::PING, [
            'latitude' => -1.2, 'longitude' => 36.9, 'route_id' => $world['route']->id,
        ])->assertStatus(202);

        Event::assertDispatched(VehicleMoved::class, fn (VehicleMoved $e) => $e->fresh
            && (int) $e->location->queue_id === (int) $ping->json('queue_id')
            && $this->channelNames($e) === ['private-trip.'.$ping->json('queue_id'), $this->channelOf($world['vehicle'])]);
    }

    // ---------------------------------------------------------------- speed

    #[Test]
    public function the_bus_carries_its_own_smoothed_speed(): void
    {
        [$world, $run, $driver] = $this->liveRunWithDriver();
        Sanctum::actingAs($driver);

        // Two fixes 30 s apart, 250 m apart: 30 km/h.
        $this->postJson(self::PING, ['latitude' => -1.2968, 'longitude' => 36.8167, 'queue_id' => $run->id, 'fixed_at' => now()->subSeconds(30)->toIso8601String()])->assertStatus(202);
        $this->assertNull($this->speedOf($world['vehicle']), 'one fix is no speed');
        $this->postJson(self::PING, ['latitude' => -1.2946, 'longitude' => 36.8167, 'queue_id' => $run->id, 'fixed_at' => now()->toIso8601String()])->assertStatus(202);
        $this->assertEqualsWithDelta(30.0, $this->speedOf($world['vehicle']), 4.0);

        // And the app reads it off buses/live.
        Sanctum::actingAs($this->makeUser());
        $this->getJson('/api/v1/auth/buses/live?keys='.SavedBuses::key($world['vehicle']->id))->assertOk()
            ->assertJsonPath('buses.0.live.speed_kmh', $this->speedOf($world['vehicle']));
    }

    #[Test]
    public function a_gps_jump_does_not_become_a_speed(): void
    {
        [$world, $run, $driver] = $this->liveRunWithDriver();
        Sanctum::actingAs($driver);
        $this->postJson(self::PING, ['latitude' => -1.2968, 'longitude' => 36.8167, 'queue_id' => $run->id, 'fixed_at' => now()->subSeconds(10)->toIso8601String()])->assertStatus(202);
        // 10 km in 10 seconds.
        $this->postJson(self::PING, ['latitude' => -1.2068, 'longitude' => 36.8167, 'queue_id' => $run->id, 'fixed_at' => now()->toIso8601String()])->assertStatus(202);

        $this->assertNull($this->speedOf($world['vehicle']));
    }

    private function speedOf(Vehicle $bus): ?float
    {
        $speed = VehicleLocation::withoutGlobalScopes()->where('vehicle_id', $bus->id)->value('speed_kmh');

        return $speed === null ? null : (float) $speed;
    }
}
