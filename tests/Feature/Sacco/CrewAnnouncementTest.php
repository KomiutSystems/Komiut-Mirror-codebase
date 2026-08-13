<?php

declare(strict_types=1);

namespace Tests\Feature\Sacco;

use App\Enums\NotificationType;
use App\Enums\UserType;
use App\Jobs\DeliverSaccoAnnouncement;
use App\Models\Sacco;
use App\Models\SaccoAnnouncement;
use App\Models\SaccoUser;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleUser;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\PlatformNotification;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A SACCO messaging its own drivers and conductors.
 *
 * Nothing could do this: every notification the system sent came from a domain
 * event (a paid booking), never from a person, so a SACCO's only channel to its
 * crew was a WhatsApp group.
 *
 * The two rules worth pinning are WHO receives it — membership, not who happens
 * to be on a bus right now — and that it cannot cross the SACCO boundary.
 */
final class CrewAnnouncementTest extends QueueTestCase
{
    private const ENDPOINT = '/api/v1/auth/sacco/announcements';

    private function driver(Sacco $sacco, bool $active = true): User
    {
        $driver = $this->makeUser([], $sacco);
        $driver->forceFill(['type' => UserType::Driver, 'status' => $active])->save();

        return $driver;
    }

    private function crewOn(Vehicle $vehicle, Sacco $sacco): User
    {
        $driver = $this->driver($sacco);
        VehicleUser::create([
            'user_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'sacco_id' => $sacco->id,
            'status' => true,
            'start_date' => now(),
        ]);

        return $driver;
    }

    #[Test]
    public function sending_queues_a_delivery_and_records_the_message(): void
    {
        $sacco = $this->makeSacco();
        $admin = $this->makeUser(['Send Crew Announcements'], $sacco);

        Sanctum::actingAs($admin);

        $this->postJson(self::ENDPOINT, ['title' => 'No service Monday', 'body' => 'Mashujaa Day — no service.'])
            ->assertStatus(201)
            ->assertJsonPath('announcement.title', 'No service Monday');

        $this->assertDatabaseHas('sacco_announcements', [
            'sacco_id' => $sacco->id,
            'user_id' => $admin->id,
            'title' => 'No service Monday',
        ]);
    }

    #[Test]
    public function it_reaches_crew_who_are_not_on_a_bus_today(): void
    {
        // The whole reason recipients are resolved from MEMBERSHIP: "no service
        // tomorrow" is exactly the message a driver who is off shift today needs,
        // and keying on an open vehicle_users row would silently drop them.
        Notification::fake();

        $sacco = $this->makeSacco();
        $onShift = $this->crewOn($this->makeVehicle($sacco, $this->makeUser([], $sacco), $this->makeSeat()), $sacco);
        $offShift = $this->driver($sacco);

        $announcement = SaccoAnnouncement::create([
            'sacco_id' => $sacco->id,
            'user_id' => $this->makeUser([], $sacco)->id,
            'title' => 'Fuel levy',
            'body' => 'Changes Monday.',
        ]);

        (new DeliverSaccoAnnouncement((int) $announcement->id))->handle(app(NotificationService::class));

        Notification::assertSentTo($onShift, PlatformNotification::class);
        Notification::assertSentTo($offShift, PlatformNotification::class);
        $this->assertSame(2, (int) $announcement->fresh()->recipients);
    }

    #[Test]
    public function it_does_not_reach_another_saccos_crew(): void
    {
        Notification::fake();

        $mine = $this->makeSacco();
        $theirs = $this->makeSacco();
        $ours = $this->driver($mine);
        $stranger = $this->driver($theirs);

        $announcement = SaccoAnnouncement::create([
            'sacco_id' => $mine->id,
            'user_id' => $this->makeUser([], $mine)->id,
            'title' => 'Crew meeting',
            'body' => 'Saturday 8am.',
        ]);

        (new DeliverSaccoAnnouncement((int) $announcement->id))->handle(app(NotificationService::class));

        Notification::assertSentTo($ours, PlatformNotification::class);
        Notification::assertNotSentTo($stranger, PlatformNotification::class);
    }

    #[Test]
    public function targeting_a_vehicle_reaches_only_that_bus(): void
    {
        Notification::fake();

        $sacco = $this->makeSacco();
        $bus = $this->makeVehicle($sacco, $this->makeUser([], $sacco), $this->makeSeat());
        $onThisBus = $this->crewOn($bus, $sacco);
        $elsewhere = $this->driver($sacco);

        $announcement = SaccoAnnouncement::create([
            'sacco_id' => $sacco->id,
            'user_id' => $this->makeUser([], $sacco)->id,
            'vehicle_id' => $bus->id,
            'title' => 'Go to the garage',
            'body' => 'Brake check before your next run.',
        ]);

        (new DeliverSaccoAnnouncement((int) $announcement->id))->handle(app(NotificationService::class));

        Notification::assertSentTo($onThisBus, PlatformNotification::class);
        Notification::assertNotSentTo($elsewhere, PlatformNotification::class);
    }

    #[Test]
    public function it_goes_out_over_in_app_realtime_and_push(): void
    {
        // The SACCO's message must travel the same three channels a booking
        // notification does -- a driver on a matatu is not looking at the app.
        Notification::fake();

        $sacco = $this->makeSacco();
        $driver = $this->driver($sacco);
        $announcement = SaccoAnnouncement::create([
            'sacco_id' => $sacco->id,
            'user_id' => $this->makeUser([], $sacco)->id,
            'title' => 'Route change',
            'body' => 'Thika Road diversion from 6am.',
        ]);

        (new DeliverSaccoAnnouncement((int) $announcement->id))->handle(app(NotificationService::class));

        Notification::assertSentTo($driver, PlatformNotification::class,
            function (PlatformNotification $n) use ($announcement, $driver) {
                $channels = $n->via($driver);

                return $n->type === NotificationType::System
                    && $n->referenceId === (string) $announcement->id
                    && in_array('database', $channels, true)
                    && in_array('broadcast', $channels, true)
                    && in_array(FcmChannel::class, $channels, true);
            });
    }

    #[Test]
    public function a_retried_delivery_does_not_push_twice(): void
    {
        // NotificationService dedupes on (recipient, referenceId, title) and
        // SKIPS the guard entirely when referenceId is null. That is why an
        // announcement is a row with an id rather than a fire-and-forget loop:
        // without one, a retried job re-pushes to every driver in the SACCO.
        //
        // The already-delivered notification is seeded directly rather than sent,
        // because Notification::fake() persists nothing — under a fake the guard
        // has no row to find and would wave the second send through, proving the
        // opposite of what this test is for.
        $sacco = $this->makeSacco();
        $driver = $this->driver($sacco);
        $announcement = SaccoAnnouncement::create([
            'sacco_id' => $sacco->id,
            'user_id' => $this->makeUser([], $sacco)->id,
            'title' => 'Payday',
            'body' => 'Friday.',
        ]);

        $driver->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => PlatformNotification::class,
            'data' => ['referenceId' => (string) $announcement->id, 'title' => 'Payday'],
        ]);

        Notification::fake();
        (new DeliverSaccoAnnouncement((int) $announcement->id))->handle(app(NotificationService::class));

        Notification::assertNotSentTo($driver, PlatformNotification::class);
    }

    #[Test]
    public function a_suspended_driver_is_not_messaged(): void
    {
        Notification::fake();

        $sacco = $this->makeSacco();
        $suspended = $this->driver($sacco, active: false);

        $announcement = SaccoAnnouncement::create([
            'sacco_id' => $sacco->id,
            'user_id' => $this->makeUser([], $sacco)->id,
            'title' => 'Crew meeting',
            'body' => 'Saturday.',
        ]);

        (new DeliverSaccoAnnouncement((int) $announcement->id))->handle(app(NotificationService::class));

        Notification::assertNotSentTo($suspended, PlatformNotification::class);
    }

    #[Test]
    public function it_refuses_without_the_permission(): void
    {
        // A mass-push surface: one call, every phone in the SACCO, no way to
        // unsend.
        $sacco = $this->makeSacco();
        Sanctum::actingAs($this->makeUser([], $sacco));

        $this->postJson(self::ENDPOINT, ['title' => 'Hi', 'body' => 'Everyone.'])
            ->assertStatus(403);

        $this->assertSame(0, SaccoAnnouncement::withoutGlobalScopes()->count());
    }

    #[Test]
    public function an_admin_cannot_target_another_saccos_vehicle(): void
    {
        $mine = $this->makeSacco();
        $theirs = $this->makeSacco();
        $victim = $this->makeVehicle($theirs, $this->makeUser([], $theirs), $this->makeSeat());

        Sanctum::actingAs($this->makeUser(['Send Crew Announcements'], $mine));

        $this->postJson(self::ENDPOINT, ['title' => 'Go to the garage', 'body' => 'Now.', 'vehicle_id' => $victim->id])
            ->assertStatus(404);

        $this->assertSame(0, SaccoAnnouncement::withoutGlobalScopes()->count());
    }

    #[Test]
    public function the_list_shows_only_this_saccos_announcements(): void
    {
        $mine = $this->makeSacco();
        $theirs = $this->makeSacco();

        // Sent through the endpoint, not inserted directly, so the row carries
        // the same brand stamp mine does — otherwise this could pass on the
        // brand scope alone and prove nothing about the SACCO boundary.
        Sanctum::actingAs($this->makeUser(['Send Crew Announcements'], $theirs));
        $this->postJson(self::ENDPOINT, ['title' => 'Theirs', 'body' => 'Not for us.'])->assertStatus(201);

        Sanctum::actingAs($this->makeUser(['Send Crew Announcements'], $mine));
        $this->postJson(self::ENDPOINT, ['title' => 'Mine', 'body' => 'Ours.'])->assertStatus(201);

        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonCount(1, 'announcements')
            ->assertJsonPath('announcements.0.title', 'Mine')
            ->assertJsonPath('meta.total', 1);
    }

    #[Test]
    public function an_empty_message_is_refused(): void
    {
        Sanctum::actingAs($this->makeUser(['Send Crew Announcements'], $this->makeSacco()));

        $this->postJson(self::ENDPOINT, ['title' => '', 'body' => ''])->assertStatus(400);
    }

    #[Test]
    public function sacco_users_membership_also_counts(): void
    {
        // Membership is maintained on two paths -- users.sacco_id and the
        // sacco_users join table. A driver added through one but not the other
        // must still be reachable.
        Notification::fake();

        $sacco = $this->makeSacco();
        $other = $this->makeSacco();
        $joined = $this->driver($other);          // home SACCO is elsewhere...
        SaccoUser::create([                        // ...but a member of this one
            'user_id' => $joined->id,
            'sacco_id' => $sacco->id,
            'status' => true,
            'start_date' => now(),
        ]);

        $announcement = SaccoAnnouncement::create([
            'sacco_id' => $sacco->id,
            'user_id' => $this->makeUser([], $sacco)->id,
            'title' => 'Crew meeting',
            'body' => 'Saturday.',
        ]);

        (new DeliverSaccoAnnouncement((int) $announcement->id))->handle(app(NotificationService::class));

        Notification::assertSentTo($joined, PlatformNotification::class);
    }
}
