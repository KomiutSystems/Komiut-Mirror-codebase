<?php

declare(strict_types=1);

namespace Tests\Feature\School;

use App\Events\VehicleMoved;
use App\Models\Queue;
use App\Models\Sacco;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleFollower;
use App\Models\VehicleUser;
use App\Providers\BroadcastServiceProvider;
use App\Services\Location\VehicleLocationService;
use App\Services\School\SchoolBuses;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A school bus's live position reaches its followers and its crew, and nobody
 * else (docs/school-bus-tracking.md, "Realtime").
 *
 * Channel callbacks are exercised through the real /broadcasting/auth route
 * against a real (locally signing) broadcaster, the way BroadcastAuthTest
 * does it -- under the suite's null broadcaster no callback would ever run.
 */
final class SchoolBusRealtimeTest extends QueueTestCase
{
    private const AUTH = '/broadcasting/auth';

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

    /** @return array{world: array, driver: User} */
    private function schoolBus(): array
    {
        $world = $this->makeWorld();
        $world['sacco']->forceFill(['kind' => Sacco::KIND_SCHOOL])->save();

        $driver = $this->makeUser([], $world['sacco']);
        VehicleUser::create([
            'user_id' => $driver->id, 'vehicle_id' => $world['vehicle']->id,
            'sacco_id' => $world['sacco']->id, 'status' => true, 'start_date' => now(),
        ]);

        return ['world' => $world, 'driver' => $driver];
    }

    private function follow(User $parent, Vehicle $bus): void
    {
        VehicleFollower::create(['user_id' => $parent->id, 'vehicle_id' => $bus->id]);
    }

    private function subscribe(User $user, Vehicle $bus): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->postJson(self::AUTH, [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-school-bus.'.$bus->id,
        ]);
    }

    /** @return array<int, string> */
    private function channelNames(VehicleMoved $event): array
    {
        return array_map(fn (PrivateChannel $c) => $c->name, $event->broadcastOn());
    }

    #[Test]
    public function a_follower_of_the_bus_is_admitted(): void
    {
        ['world' => $world] = $this->schoolBus();
        $parent = $this->makeUser();
        $this->follow($parent, $world['vehicle']);

        $this->subscribe($parent, $world['vehicle'])->assertOk()->assertJsonStructure(['auth']);
    }

    #[Test]
    public function the_crew_is_admitted_and_a_crew_member_who_came_off_the_bus_is_not(): void
    {
        ['world' => $world, 'driver' => $driver] = $this->schoolBus();

        $this->subscribe($driver, $world['vehicle'])->assertOk();

        VehicleUser::withoutGlobalScopes()->where('user_id', $driver->id)->update(['end_date' => now()]);
        $this->subscribe($driver, $world['vehicle'])->assertForbidden();
    }

    #[Test]
    public function a_stranger_and_a_follower_of_another_bus_are_refused(): void
    {
        ['world' => $world] = $this->schoolBus();
        ['world' => $other] = $this->schoolBus();
        $otherParent = $this->makeUser();
        $this->follow($otherParent, $other['vehicle']);

        $this->subscribe($this->makeUser(), $world['vehicle'])->assertForbidden();
        $this->subscribe($otherParent, $world['vehicle'])->assertForbidden();
        // ...while the bus they do follow admits them: refused for the bus,
        // not for being a parent.
        $this->subscribe($otherParent, $other['vehicle'])->assertOk();
    }

    #[Test]
    public function a_bus_that_is_not_a_school_bus_has_no_school_channel(): void
    {
        $matatu = $this->makeWorld();
        $parent = $this->makeUser();
        // A row that should never exist, but if it did it must open nothing.
        $this->follow($parent, $matatu['vehicle']);

        $this->subscribe($parent, $matatu['vehicle'])->assertForbidden();
    }

    #[Test]
    public function a_school_bus_ping_goes_to_its_followers_as_well_as_the_trip(): void
    {
        ['world' => $world] = $this->schoolBus();
        $run = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'],
            $this->makeQueueStatus('Active', 'Active'), $world['owner']);

        $event = new VehicleMoved($this->pingFrom($world['vehicle'], $run), $world['vehicle']->plate);

        $this->assertSame(['private-trip.'.$run->id, 'private-school-bus.'.$world['vehicle']->id], $this->channelNames($event));
        // The trip payload is unchanged, and carries nothing about who is aboard.
        $this->assertSame(
            ['vehicle_id', 'queue_id', 'plate', 'latitude', 'longitude', 'heading', 'recorded_at'],
            array_keys($event->broadcastWith()),
        );
    }

    #[Test]
    public function a_school_bus_with_no_run_still_reaches_its_followers(): void
    {
        ['world' => $world] = $this->schoolBus();

        $event = new VehicleMoved($this->pingFrom($world['vehicle']), $world['vehicle']->plate);

        $this->assertSame(['private-school-bus.'.$world['vehicle']->id], $this->channelNames($event));
    }

    #[Test]
    public function a_stale_fix_is_not_sent_to_followers(): void
    {
        ['world' => $world] = $this->schoolBus();

        $stale = $this->pingFrom($world['vehicle'], null, ageSeconds: VehicleLocationService::FRESH_SECONDS + 30);

        $this->assertSame([], $this->channelNames(new VehicleMoved($stale)));
    }

    #[Test]
    public function a_matatu_ping_goes_to_the_trip_only(): void
    {
        $matatu = $this->makeWorld();
        $run = $this->makeQueue($matatu['vehicle'], $matatu['terminus'], $matatu['route'],
            $this->makeQueueStatus('Active', 'Active'), $matatu['owner']);

        $event = new VehicleMoved($this->pingFrom($matatu['vehicle'], $run));

        $this->assertSame(['private-trip.'.$run->id], $this->channelNames($event));
    }

    #[Test]
    public function the_school_check_costs_no_query_once_cached(): void
    {
        $matatu = $this->makeWorld();
        $location = $this->pingFrom($matatu['vehicle']);
        SchoolBuses::isSchoolBus((int) $matatu['vehicle']->id);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        new VehicleMoved($location);
        SchoolBuses::isSchoolBus((int) $matatu['vehicle']->id);

        $this->assertSame(0, $queries, 'every ping asks; only the first may reach the database');
    }

    #[Test]
    public function the_driver_ping_dispatches_the_event_for_followers(): void
    {
        Event::fake([VehicleMoved::class]);
        ['world' => $world, 'driver' => $driver] = $this->schoolBus();
        $this->makeQueueStatus('Active', 'Active');

        Sanctum::actingAs($driver);
        $ping = $this->postJson('/api/v1/auth/book_a_ride/location', [
            'latitude' => -1.2, 'longitude' => 36.9, 'route_id' => $world['route']->id,
        ])->assertStatus(202);

        Event::assertDispatched(VehicleMoved::class, fn (VehicleMoved $e) => $e->toFollowers
            && (int) $e->location->queue_id === (int) $ping->json('queue_id')
            && in_array('private-school-bus.'.$world['vehicle']->id, $this->channelNames($e), true));
        $this->assertTrue(Queue::withoutGlobalScopes()->whereKey($ping->json('queue_id'))->exists());
    }
}
