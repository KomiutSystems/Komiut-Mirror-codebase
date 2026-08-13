<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\NotificationType;
use App\Enums\UserType;
use App\Events\BookingPaid;
use App\Models\FirebaseToken;
use App\Models\User;
use App\Models\VehicleUser;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\PlatformNotification;
use App\Services\Notifications\FcmSender;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The push a driver actually depends on, and the notification they get most.
 *
 * Two things were untested and both matter to a driver specifically:
 *
 *  - NotifyBookingConfirmed notifies the PASSENGER and the DRIVER. Only the
 *    passenger half had a test, so the driver's "a seat on your trip has just
 *    been booked and paid" -- the most frequent notification in the system --
 *    could have stopped working and the suite would have stayed green.
 *
 *  - FcmChannel had no test at all. Nothing verified that it resolves the
 *    recipient's device tokens, sends one push per device, or survives a dead
 *    token. A driver on a matatu is not looking at the app; push IS the channel,
 *    and it was the only one nothing exercised end to end.
 */
final class DriverPushTest extends QueueTestCase
{
    /** Records what would have been pushed, instead of calling Google. */
    private function fakeSender(bool $failEveryOther = false): FcmSender
    {
        return new class($failEveryOther) extends FcmSender
        {
            /** @var array<int, array<string, mixed>> */
            public array $sent = [];

            public int $calls = 0;

            public function __construct(private readonly bool $failEveryOther) {}

            public function send(string $token, string $title, string $body, array $data): bool
            {
                $this->calls++;

                // Simulates a stale token: FcmSender is best-effort and returns
                // false rather than throwing, so the loop must carry on.
                if ($this->failEveryOther && $this->calls % 2 === 1) {
                    return false;
                }

                $this->sent[] = compact('token', 'title', 'body', 'data');

                return true;
            }
        };
    }

    private function driverOnAVehicle(): array
    {
        $world = $this->makeWorld();
        $driver = $this->makeUser([], $world['sacco']);
        $driver->forceFill(['type' => UserType::Driver])->save();

        VehicleUser::create([
            'user_id' => $driver->id,
            'vehicle_id' => $world['vehicle']->id,
            'sacco_id' => $world['sacco']->id,
            'status' => true,
            'start_date' => now(),
        ]);

        return $world + ['driver' => $driver];
    }

    private function token(User $user, string $token): void
    {
        FirebaseToken::create([
            'user_id' => $user->id,
            'firebase_token' => $token,
            'platform' => 'android',
            'device_id' => 'dev-'.$token,
        ]);
    }

    #[Test]
    public function paying_a_booking_notifies_the_driver_of_the_vehicle(): void
    {
        Notification::fake();

        $world = $this->driverOnAVehicle();
        $queue = $this->makeQueue(
            $world['vehicle'], $world['terminus'], $world['route'],
            $this->makeQueueStatus('Pending '.$this->nextSequence(), 'Pending'),
            $world['owner'], 'QN-'.$this->nextSequence(),
        );
        $booking = $this->makeBooking($queue, $this->makeUser([], $world['sacco']),
            $world['from'], $world['to'], 'Wanjiku');

        BookingPaid::dispatch($booking);

        Notification::assertSentTo(
            $world['driver'],
            PlatformNotification::class,
            fn (PlatformNotification $n) => $n->title === 'New booking'
                && $n->type === NotificationType::Assignment
                // referenceId is the booking id so the push deep-links.
                && $n->referenceId === (string) $booking->id,
        );
    }

    #[Test]
    public function a_driver_with_no_open_assignment_is_not_notified(): void
    {
        // The notification is keyed on whoever is driving the vehicle NOW.
        // Yesterday's driver must not keep receiving today's bookings.
        Notification::fake();

        $world = $this->driverOnAVehicle();
        VehicleUser::where('user_id', $world['driver']->id)
            ->update(['status' => false, 'end_date' => now()]);

        $queue = $this->makeQueue(
            $world['vehicle'], $world['terminus'], $world['route'],
            $this->makeQueueStatus('Pending '.$this->nextSequence(), 'Pending'),
            $world['owner'], 'QN-'.$this->nextSequence(),
        );
        $booking = $this->makeBooking($queue, $this->makeUser([], $world['sacco']),
            $world['from'], $world['to'], 'Otieno');

        BookingPaid::dispatch($booking);

        Notification::assertNotSentTo($world['driver'], PlatformNotification::class);
    }

    #[Test]
    public function the_push_reaches_every_device_the_driver_registered(): void
    {
        // A driver signs in on a new handset without unregistering the old one,
        // so more than one live token per person is normal rather than an edge
        // case.
        $world = $this->driverOnAVehicle();
        $this->token($world['driver'], 'token-phone');
        $this->token($world['driver'], 'token-tablet');

        $sender = $this->fakeSender();
        (new FcmChannel($sender))->send($world['driver'], new PlatformNotification(
            type: NotificationType::Assignment,
            title: 'New booking',
            message: 'A seat on your trip has just been booked and paid.',
            referenceId: '42',
        ));

        $this->assertCount(2, $sender->sent);
        $this->assertEqualsCanonicalizing(
            ['token-phone', 'token-tablet'],
            array_column($sender->sent, 'token'),
        );
    }

    #[Test]
    public function the_push_carries_the_keys_the_app_deep_links_on(): void
    {
        // The app's handler switches on data.type and opens the ticket only for
        // a non-empty referenceId. Get these wrong and the push still arrives,
        // but tapping it goes nowhere.
        $world = $this->driverOnAVehicle();
        $this->token($world['driver'], 'token-phone');

        $sender = $this->fakeSender();
        (new FcmChannel($sender))->send($world['driver'], new PlatformNotification(
            type: NotificationType::Trip,
            title: 'New booking',
            message: 'A seat has been booked.',
            referenceId: '42',
        ));

        $this->assertSame('New booking', $sender->sent[0]['title']);
        $this->assertSame('trip', $sender->sent[0]['data']['type']);
        $this->assertSame('42', $sender->sent[0]['data']['referenceId']);
    }

    #[Test]
    public function one_dead_token_does_not_stop_the_others(): void
    {
        // Tokens go stale when an app is reinstalled. Sends are independent and
        // best-effort; a driver's working handset must still get the push.
        $world = $this->driverOnAVehicle();
        $this->token($world['driver'], 'token-dead');
        $this->token($world['driver'], 'token-live');

        $sender = $this->fakeSender(failEveryOther: true);
        (new FcmChannel($sender))->send($world['driver'], new PlatformNotification(
            type: NotificationType::System,
            title: 'Route change',
            message: 'Thika Road diversion from 6am.',
        ));

        $this->assertSame(2, $sender->calls, 'Both tokens must be attempted.');
        $this->assertCount(1, $sender->sent);
    }

    #[Test]
    public function a_driver_with_no_device_gets_no_push_and_no_error(): void
    {
        // In-app and realtime still carry it; a driver who has never opened the
        // app must not break the fan-out for everyone else in the SACCO.
        $world = $this->driverOnAVehicle();

        $sender = $this->fakeSender();
        (new FcmChannel($sender))->send($world['driver'], new PlatformNotification(
            type: NotificationType::System,
            title: 'Crew meeting',
            message: 'Saturday 8am.',
        ));

        $this->assertSame(0, $sender->calls);
    }

    #[Test]
    public function a_push_only_goes_to_its_own_recipient(): void
    {
        $world = $this->driverOnAVehicle();
        $other = $this->makeUser([], $world['sacco']);
        $this->token($world['driver'], 'token-mine');
        $this->token($other, 'token-theirs');

        $sender = $this->fakeSender();
        (new FcmChannel($sender))->send($world['driver'], new PlatformNotification(
            type: NotificationType::System,
            title: 'Crew meeting',
            message: 'Saturday 8am.',
        ));

        $this->assertSame(['token-mine'], array_column($sender->sent, 'token'));
    }

    #[Test]
    public function the_credentials_are_read_from_the_local_disk_not_the_default_one(): void
    {
        // Frankfurt runs FILESYSTEM_DISK=s3, but the service-account JSON is
        // baked into the image at storage/app/json/. Resolving it against the
        // DEFAULT disk threw
        // "Class League\Flysystem\AwsS3V3\PortableVisibilityConverter not found"
        // -- with the file sitting right there on local disk.
        config(['filesystems.default' => 's3']);
        Storage::disk('local')->put('json/test-fcm.json', '{"type":"service_account"}');
        config([
            'services.fcm.komiut.credentials' => 'json/test-fcm.json',
            'services.fcm.komiut.project_id' => 'komiut',
        ]);

        $config = $this->brandConfig(new FcmSender);

        $this->assertNotNull($config, 'The credentials must resolve regardless of the default disk.');
        $this->assertTrue(is_file($config['credentials']));

        Storage::disk('local')->delete('json/test-fcm.json');
    }

    #[Test]
    public function an_unresolvable_credentials_path_returns_false_instead_of_throwing(): void
    {
        // The class contract is best-effort: "a dead token or unconfigured brand
        // must never break the dispatch". It was broken -- brandConfig() runs
        // BEFORE send()'s try block, so the throw took the whole queued
        // notification with it. A failed ShouldQueue notification then retries
        // and re-runs EVERY channel, so each retry also wrote another in-app row
        // and fired another broadcast. Worse than silence.
        config([
            'services.fcm.komiut.credentials' => 'json/does-not-exist.json',
            'services.fcm.komiut.project_id' => 'komiut',
        ]);

        $this->assertFalse(
            (new FcmSender)->send('token', 'Title', 'Body', ['type' => 'system', 'referenceId' => '']),
        );
    }

    #[Test]
    public function a_brand_with_no_firebase_project_sends_nothing_and_does_not_throw(): void
    {
        // 2Safiri has no Firebase project yet (SAFIRI_FCM_* are unset), so its
        // drivers get in-app and realtime but no push. That must stay a quiet
        // no-op rather than a failing job.
        config(['services.fcm.safiri' => ['project_id' => null, 'credentials' => null]]);
        Context::add('brand', 'safiri');

        $this->assertFalse(
            (new FcmSender)->send('token', 'Title', 'Body', ['type' => 'system', 'referenceId' => '']),
        );
    }

    /**
     * brandConfig() is private and this is the one behaviour worth pinning
     * directly: everything downstream of it fails identically, so an indirect
     * assertion could not tell "wrong disk" from "bad credentials".
     *
     * @return array{project_id: string, credentials: string}|null
     */
    private function brandConfig(FcmSender $sender): ?array
    {
        $method = new \ReflectionMethod($sender, 'brandConfig');
        $method->setAccessible(true);

        return $method->invoke($sender);
    }
}
