<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\NotificationType;
use App\Enums\UserType;
use App\Models\Sacco;
use App\Models\User;
use App\Models\VehicleUser;
use App\Notifications\PlatformNotification;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Being taken off a matatu.
 *
 * The handover was completely silent. A new driver signs in against a plate,
 * and the previous driver's assignment closes AND their open queue -- with its
 * bookings and its fare -- is cancelled. They found out by opening the app to an
 * empty screen, mid-shift, with passengers already seated.
 *
 * The other half is quieter: a driver who signed in themselves already knows
 * where they are. But a SACCO admin can attach a driver to a vehicle from the
 * dashboard, and that driver has no other way to hear about it.
 */
final class CrewChangeNotifiesTest extends QueueTestCase
{
    private const ENDPOINT = '/api/v1/auth/driver/login';

    private function driver(Sacco $sacco, string $phone): User
    {
        $driver = $this->makeUser([], $sacco);
        $driver->forceFill(['type' => UserType::Driver, 'phone' => $phone])->save();

        return $driver;
    }

    #[Test]
    public function the_outgoing_driver_is_told_they_lost_the_vehicle(): void
    {
        Notification::fake();

        $world = $this->makeWorld();
        $outgoing = $this->driver($world['sacco'], '254711000111');
        $incoming = $this->driver($world['sacco'], '254711000222');
        $plate = $world['vehicle']->plate;

        $this->postJson(self::ENDPOINT, ['phone' => '254711000111', 'plate' => $plate])->assertOk();
        $this->postJson(self::ENDPOINT, ['phone' => '254711000222', 'plate' => $plate])->assertOk();

        Notification::assertSentTo(
            $outgoing,
            PlatformNotification::class,
            fn (PlatformNotification $n) => $n->type === NotificationType::Assignment
                && $n->title === 'You are off '.$plate,
        );
        $this->assertNotNull($incoming->fresh());
    }

    #[Test]
    public function losing_a_trip_with_it_says_so(): void
    {
        // Losing a shift is one message; losing a shift with passengers already
        // booked on it is another, and the driver has to explain that to them.
        Notification::fake();

        $world = $this->makeWorld();
        $outgoing = $this->driver($world['sacco'], '254711000111');
        $this->driver($world['sacco'], '254711000222');
        $plate = $world['vehicle']->plate;

        // The outgoing driver has to actually BE on the vehicle: cancelOpenQueues
        // only touches the queues of drivers this handover displaced, so without
        // the sign-in there is nobody to displace and nothing to cancel.
        $this->postJson(self::ENDPOINT, ['phone' => '254711000111', 'plate' => $plate])->assertOk();

        $pending = $this->makeQueueStatus('Pending '.$this->nextSequence(), 'Pending');
        $this->makeQueueStatus('Cancelled '.$this->nextSequence(), 'Cancelled');
        $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $outgoing,
            'QN-'.$this->nextSequence());

        $this->postJson(self::ENDPOINT, ['phone' => '254711000222', 'plate' => $plate])->assertOk();

        Notification::assertSentTo(
            $outgoing,
            PlatformNotification::class,
            fn (PlatformNotification $n) => str_contains($n->message, 'cancelled'),
        );
    }

    #[Test]
    public function the_incoming_driver_is_told_which_vehicle_they_are_on(): void
    {
        // A SACCO admin can attach a driver from the dashboard, and that driver
        // has no other way to hear about it.
        Notification::fake();

        $world = $this->makeWorld();
        $driver = $this->driver($world['sacco'], '254711000111');
        $plate = $world['vehicle']->plate;

        $this->postJson(self::ENDPOINT, ['phone' => '254711000111', 'plate' => $plate])->assertOk();

        Notification::assertSentTo(
            $driver,
            PlatformNotification::class,
            fn (PlatformNotification $n) => $n->title === 'You are on '.$plate,
        );
    }

    #[Test]
    public function signing_in_again_on_the_same_vehicle_says_nothing(): void
    {
        // The daily re-login. Nothing moved, so there is nothing to report --
        // and a driver who gets "You are on KDA123X" every single morning stops
        // reading notifications altogether.
        $world = $this->makeWorld();
        $driver = $this->driver($world['sacco'], '254711000111');
        $plate = $world['vehicle']->plate;

        $this->postJson(self::ENDPOINT, ['phone' => '254711000111', 'plate' => $plate])->assertOk();

        Notification::fake();
        $this->postJson(self::ENDPOINT, ['phone' => '254711000111', 'plate' => $plate])->assertOk();

        Notification::assertNothingSentTo($driver);
    }

    #[Test]
    public function a_non_driver_on_the_vehicle_is_not_told_anything(): void
    {
        // The rotation only closes the outgoing DRIVER's attachment; an owner or
        // admin row on the same vehicle is left alone, so they must not be told
        // they lost a bus they never drove.
        Notification::fake();

        $world = $this->makeWorld();
        $this->driver($world['sacco'], '254711000222');

        $admin = $this->makeUser([], $world['sacco']);
        $admin->forceFill(['type' => UserType::Admin])->save();
        VehicleUser::create([
            'user_id' => $admin->id,
            'vehicle_id' => $world['vehicle']->id,
            'sacco_id' => $world['sacco']->id,
            'status' => true,
            'start_date' => now(),
        ]);

        $this->postJson(self::ENDPOINT, ['phone' => '254711000222', 'plate' => $world['vehicle']->plate])->assertOk();

        Notification::assertNothingSentTo($admin);
    }

    #[Test]
    public function being_taken_off_the_same_vehicle_twice_is_reported_twice(): void
    {
        // NotificationService dedupes on (recipient, referenceId, title). Keying
        // the reference on the VEHICLE would mean a driver rotated off the same
        // bus next week never hears about it. The closed assignment row is
        // unique per handover, which is why it is used instead.
        //
        // Asserted on the referenceIds rather than on stored rows: the suite
        // never lets a PlatformNotification persist (it is ShouldQueue +
        // afterCommit, and RefreshDatabase's transaction never commits), so
        // counting rows would measure the harness, not the behaviour. The two
        // references being DIFFERENT is the mechanism that stops the dedupe.
        Notification::fake();

        $world = $this->makeWorld();
        $a = $this->driver($world['sacco'], '254711000111');
        $this->driver($world['sacco'], '254711000222');
        $plate = $world['vehicle']->plate;

        $this->postJson(self::ENDPOINT, ['phone' => '254711000111', 'plate' => $plate])->assertOk();
        $this->postJson(self::ENDPOINT, ['phone' => '254711000222', 'plate' => $plate])->assertOk();
        // A takes it back, then loses it again.
        $this->postJson(self::ENDPOINT, ['phone' => '254711000111', 'plate' => $plate])->assertOk();
        $this->postJson(self::ENDPOINT, ['phone' => '254711000222', 'plate' => $plate])->assertOk();

        $references = [];
        Notification::assertSentTo($a, PlatformNotification::class,
            function (PlatformNotification $n) use ($plate, &$references) {
                if ($n->title === 'You are off '.$plate) {
                    $references[] = $n->referenceId;
                }

                return true;
            });

        $this->assertCount(2, $references, 'Both handovers must be reported.');
        $this->assertCount(2, array_unique($references),
            'A second handover must carry its own reference, or the dedupe swallows it.');
    }
}
