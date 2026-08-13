<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\UserType;
use App\Models\VehicleUser;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * /broadcasting/auth, and the channel callbacks behind it.
 *
 * This route did not exist for months. `config/app.php` ships
 * BroadcastServiceProvider commented out, so `Broadcast::routes()` never ran and
 * `routes/channels.php` was never even loaded — every private channel we have
 * was unreachable and the mobile app could not subscribe to anything.
 *
 * It hid because the FRAMEWORK's broadcasting provider is a different class and
 * was always registered: the server emitted events perfectly, and only the
 * subscribe half was missing. So the tests that matter are (a) the route
 * answers at all, which is the regression guard for that one line, and (b) each
 * callback in channels.php actually runs — none of them had ever executed in
 * CI.
 */
final class BroadcastAuthTest extends QueueTestCase
{
    private const AUTH = '/broadcasting/auth';

    /** @return array<string,mixed> */
    private function crewedWorld(): array
    {
        $world = $this->makeWorld();
        $queue = $this->makeQueue(
            $world['vehicle'], $world['terminus'], $world['route'],
            $this->makeQueueStatus('Pending '.$this->nextSequence(), 'Pending'),
            $world['owner'],
            'QN-'.$this->nextSequence(),
        );

        $driver = $this->makeUser([], $world['sacco']);
        $driver->forceFill(['type' => UserType::Driver])->save();
        VehicleUser::create([
            'user_id' => $driver->id,
            'vehicle_id' => $world['vehicle']->id,
            'sacco_id' => $world['sacco']->id,
            'status' => true,
            'start_date' => now(),
        ]);

        return $world + ['queue' => $queue, 'driver' => $driver];
    }

    #[Test]
    public function the_route_exists_at_all(): void
    {
        // The whole point of this file. A 404 here means the provider line went
        // back to commented and realtime is silently dead again.
        $user = $this->makeUser([], $this->makeSacco());
        Sanctum::actingAs($user);

        $this->postJson(self::AUTH, [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-App.Models.User.'.$user->id,
        ])->assertOk()->assertJsonStructure(['auth']);
    }

    #[Test]
    public function it_needs_authentication(): void
    {
        $this->postJson(self::AUTH, [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-App.Models.User.1',
        ])->assertStatus(401);
    }

    #[Test]
    public function a_user_cannot_listen_on_someone_elses_channel(): void
    {
        $sacco = $this->makeSacco();
        $mine = $this->makeUser([], $sacco);
        $theirs = $this->makeUser([], $sacco);

        Sanctum::actingAs($mine);

        $this->postJson(self::AUTH, [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-App.Models.User.'.$theirs->id,
        ])->assertStatus(403);
    }

    #[Test]
    public function the_crew_driving_a_trip_may_listen_to_it(): void
    {
        $world = $this->crewedWorld();

        Sanctum::actingAs($world['driver']);

        $this->postJson(self::AUTH, [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-trip.'.$world['queue']->id,
        ])->assertOk();
    }

    #[Test]
    public function a_passenger_who_booked_the_trip_may_listen_to_it(): void
    {
        $world = $this->crewedWorld();
        $passenger = $this->makeUser([], $world['sacco']);
        $this->makeBooking($world['queue'], $passenger, $world['from'], $world['to'], 'Wanjiku');

        Sanctum::actingAs($passenger);

        $this->postJson(self::AUTH, [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-trip.'.$world['queue']->id,
        ])->assertOk();
    }

    #[Test]
    public function a_stranger_cannot_follow_a_trip(): void
    {
        // A vehicle's live position is who-is-where information. Neither booking
        // it nor driving it means no listening to it, even inside the SACCO.
        $world = $this->crewedWorld();

        Sanctum::actingAs($this->makeUser([], $world['sacco']));

        $this->postJson(self::AUTH, [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-trip.'.$world['queue']->id,
        ])->assertStatus(403);
    }

    #[Test]
    public function only_a_super_admin_reaches_the_platform_channel(): void
    {
        Sanctum::actingAs($this->makeUser([], $this->makeSacco()));

        $this->postJson(self::AUTH, [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-super',
        ])->assertStatus(403);
    }
}
