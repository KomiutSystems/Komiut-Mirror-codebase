<?php

declare(strict_types=1);

namespace Tests\Feature\Buses;

use App\Events\VehicleMoved;
use App\Models\Queue;
use App\Models\Sacco;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLocation;
use App\Models\VehicleUser;
use App\Providers\BroadcastServiceProvider;
use App\Services\Location\VehicleLocationService;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A saved bus's live position: the bus.{id} channel any signed-in user may
 * listen on while the bus is in service, the one VehicleMoved broadcast that
 * reaches it, and the smoothed speed the app works out its own arrival
 * estimate from (docs/school-bus-tracking.md, "Saved buses").
 *
 * Channel callbacks are exercised through the real /broadcasting/auth route
 * against a real (locally signing) broadcaster, the way BroadcastAuthTest
 * does it -- under the suite's null broadcaster no callback would ever run.
 */
final class BusChannelTest extends QueueTestCase
{
    private const AUTH = '/broadcasting/auth';

    private const PING = '/api/v1/auth/book_a_ride/location';

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
    }

    private function subscribe(User $user, string $channel): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->postJson(self::AUTH, ['socket_id' => '1234.5678', 'channel_name' => $channel]);
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
    public function any_signed_in_user_may_listen_to_a_bus_in_service(): void
    {
        $matatu = $this->makeWorld()['vehicle'];
        $school = $this->makeWorld();
        $school['sacco']->forceFill(['kind' => Sacco::KIND_SCHOOL])->save();
        // A stranger, and someone in another SACCO altogether.
        foreach ([$this->makeUser(), $this->makeUser([], $this->makeSacco())] as $user) {
            foreach ([$matatu, $school['vehicle']] as $bus) {
                $this->subscribe($user, 'private-bus.'.$bus->id)->assertOk()->assertJsonStructure(['auth']);
            }
        }
    }

    #[Test]
    public function an_unknown_or_out_of_service_bus_admits_nobody(): void
    {
        $user = $this->makeUser();
        $this->subscribe($user, 'private-bus.99999999')->assertForbidden();

        $off = $this->makeWorld()['vehicle'];
        $off->forceFill(['status' => false])->save();
        $this->subscribe($user, 'private-bus.'.$off->id)->assertForbidden();

        $offOperator = $this->makeWorld();
        $offOperator['sacco']->forceFill(['status' => 0])->save();
        $this->subscribe($user, 'private-bus.'.$offOperator['vehicle']->id)->assertForbidden();
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
    public function a_fresh_ping_goes_to_the_trip_and_the_bus_in_one_broadcast(): void
    {
        foreach ([false, true] as $school) {
            [$world, $run] = $this->liveRunWithDriver($school);
            $event = new VehicleMoved($this->pingFrom($world['vehicle'], $run), $world['vehicle']->plate);

            $this->assertSame(['private-trip.'.$run->id, 'private-bus.'.$world['vehicle']->id], $this->channelNames($event));
            $this->assertSame('vehicle.moved', $event->broadcastAs());
            $this->assertSame(
                ['vehicle_id', 'queue_id', 'plate', 'latitude', 'longitude', 'heading', 'recorded_at'],
                array_keys($event->broadcastWith()),
            );
        }
    }

    #[Test]
    public function a_bus_with_no_run_still_reaches_its_channel(): void
    {
        $bus = $this->makeWorld()['vehicle'];

        $this->assertSame(['private-bus.'.$bus->id], $this->channelNames(new VehicleMoved($this->pingFrom($bus))));
    }

    #[Test]
    public function a_stale_fix_is_not_sent_to_the_bus_channel(): void
    {
        [$world, $run] = $this->liveRunWithDriver();
        $stale = $this->pingFrom($world['vehicle'], $run, ageSeconds: VehicleLocationService::FRESH_SECONDS + 30);

        $this->assertSame(['private-trip.'.$run->id], $this->channelNames(new VehicleMoved($stale)));
    }

    #[Test]
    public function the_driver_ping_dispatches_the_event_for_the_bus_channel(): void
    {
        Event::fake([VehicleMoved::class]);
        [$world, , $driver] = $this->liveRunWithDriver(school: true);

        Sanctum::actingAs($driver);
        $ping = $this->postJson(self::PING, [
            'latitude' => -1.2, 'longitude' => 36.9, 'route_id' => $world['route']->id,
        ])->assertStatus(202);

        Event::assertDispatched(VehicleMoved::class, fn (VehicleMoved $e) => $e->fresh
            && (int) $e->location->queue_id === (int) $ping->json('queue_id')
            && $this->channelNames($e) === ['private-trip.'.$ping->json('queue_id'), 'private-bus.'.$world['vehicle']->id]);
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
        $this->getJson('/api/v1/auth/buses/live?ids='.$world['vehicle']->id)->assertOk()
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
