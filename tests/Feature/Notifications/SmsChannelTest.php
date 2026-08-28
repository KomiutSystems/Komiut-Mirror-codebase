<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\NotificationType;
use App\Jobs\SendSMSJob;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\PlatformNotification;
use App\Services\Notifications\SmsSender;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The SMS channel: a notification that declares 'sms' reaches the SMS sender
 * without its caller writing a single dispatch by hand.
 *
 * Why the channel is exercised directly rather than through $user->notify():
 * PlatformNotification is ShouldQueue + afterCommit, and RefreshDatabase holds
 * an uncommitted transaction for the whole test, so a real dispatch is never
 * even enqueued (the same reason NotificationTest builds its rows by hand). The
 * mapping from 'sms' to this channel is asserted separately, on via().
 *
 * Bus::fake() comes from QueueTestCase — it stops SendSMSJob running inline on
 * the sync connection and firing a real HTTP call at the SMS gateway.
 */
final class SmsChannelTest extends QueueTestCase
{
    private function notification(string $message = 'Your seat is held for 10 minutes.'): PlatformNotification
    {
        return new PlatformNotification(
            type: NotificationType::Trip,
            title: 'Booking created',
            message: $message,
            referenceId: '42',
            channels: ['database', 'sms'],
        );
    }

    #[Test]
    public function the_channel_hands_the_message_to_the_sms_sender(): void
    {
        Bus::fake();
        $user = $this->makeUser();
        $user->forceFill(['phone' => '0712345678'])->save();

        app(SmsChannel::class)->send($user, $this->notification());

        Bus::assertDispatched(
            SendSMSJob::class,
            fn (SendSMSJob $job) => $this->jobPhone($job) === '254712345678'
                && $this->jobMessage($job) === 'Your seat is held for 10 minutes.'
        );
    }

    #[Test]
    public function the_sms_body_is_the_message_without_the_title(): void
    {
        // SMS bills per 160-char segment. Prefixing the title would duplicate
        // wording the message already carries and can push a routine
        // notification into a second, chargeable segment.
        $user = $this->makeUser();

        $this->assertSame(
            'Your seat is held for 10 minutes.',
            $this->notification()->toSms($user)
        );
    }

    #[Test]
    public function platform_notification_maps_the_sms_channel(): void
    {
        $user = $this->makeUser();

        $via = $this->notification()->via($user);

        $this->assertContains(SmsChannel::class, $via);
        // The other channels are untouched — 'sms' is additive, not a swap.
        $this->assertContains('database', $via);
    }

    #[Test]
    public function a_notification_without_toSms_is_ignored(): void
    {
        // The channel must be safe to list on any notification: one that never
        // declares toSms() simply sends nothing rather than erroring.
        Bus::fake();
        $user = $this->makeUser();
        $user->forceFill(['phone' => '0712345678'])->save();

        $bare = new class extends \Illuminate\Notifications\Notification {};

        app(SmsChannel::class)->send($user, $bare);

        Bus::assertNotDispatched(SendSMSJob::class);
    }

    #[Test]
    public function a_recipient_without_a_usable_number_is_skipped_not_thrown(): void
    {
        // 6,808 user rows exist and plenty carry junk or empty phones. A bad
        // number must not throw inside the queued notification job, because
        // Laravel sends channels in sequence — a throw here would also lose the
        // in-app row for a channel listed after 'sms'.
        Bus::fake();
        $user = $this->makeUser();
        $user->forceFill(['phone' => 'not-a-number'])->save();

        app(SmsChannel::class)->send($user, $this->notification());

        Bus::assertNotDispatched(SendSMSJob::class);
    }

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function numberProvider(): array
    {
        return [
            'national prefix'      => ['0712345678', '254712345678'],
            'already msisdn'       => ['254712345678', '254712345678'],
            'plus prefixed'        => ['+254712345678', '254712345678'],
            'spaced'               => ['0712 345 678', '254712345678'],
            'bare subscriber'      => ['712345678', '254712345678'],
            'safaricom 1-series'   => ['0110123456', '254110123456'],
            'too short'            => ['0712345', null],
            'wrong country'        => ['+2557123456789', null],
            'empty'                => ['', null],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('numberProvider')]
    public function numbers_are_normalised_or_rejected(string $input, ?string $expected): void
    {
        // AuthController builds MSISDNs as '254'.intval($phone) while imported
        // rows already sit in 254… form, so all four shapes are live in this
        // database and must land on the same string. Anything that does not
        // resolve to a real Kenyan MSISDN is dropped: an SMS carrying a booking
        // or a reset password reaching a stranger is worse than no SMS.
        Bus::fake();
        $user = $this->makeUser();
        $user->forceFill(['phone' => $input])->save();

        app(SmsChannel::class)->send($user, $this->notification());

        if ($expected === null) {
            Bus::assertNotDispatched(SendSMSJob::class);

            return;
        }

        Bus::assertDispatched(SendSMSJob::class, fn (SendSMSJob $job) => $this->jobPhone($job) === $expected);
    }

    #[Test]
    public function the_sender_rejects_an_empty_message(): void
    {
        Bus::fake();

        $this->assertFalse(app(SmsSender::class)->send('254712345678', '   '));

        Bus::assertNotDispatched(SendSMSJob::class);
    }

    /** SendSMSJob keeps its payload protected; read it the way the queue would. */
    private function jobPhone(SendSMSJob $job): string
    {
        return (string) $this->jobProperty($job, 'phone');
    }

    private function jobMessage(SendSMSJob $job): string
    {
        return (string) $this->jobProperty($job, 'message');
    }

    private function jobProperty(SendSMSJob $job, string $name): mixed
    {
        $property = new \ReflectionProperty($job, $name);
        $property->setAccessible(true);

        return $property->getValue($job);
    }
}
