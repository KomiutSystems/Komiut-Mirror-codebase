<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * One notification class for the whole platform — the Laravel equivalent of the
 * C# single-dispatcher pattern. Every event builds one of these (via
 * NotificationService) with a type, title, message, optional referenceId, and
 * the channels it should reach. One payload shape feeds all three channels
 * (in-app, realtime, push), so REST, broadcast and FCM never diverge — exactly
 * the property the app depends on.
 *
 * CRITICAL — this is ShouldQueue and dispatched afterCommit. It is fired from
 * inside payment transactions (booking marked paid); if it ran synchronously and
 * a push/email throw bubbled up, the transaction would roll back and a paid
 * booking would be lost. Queueing moves the work out of the transaction, and
 * afterCommit guarantees the job is never even enqueued until the payment commits.
 */
class PlatformNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, string>  $channels  any of: database, broadcast, fcm, sms, mail
     */
    public function __construct(
        public readonly NotificationType $type,
        public readonly string $title,
        public readonly string $message,
        public readonly ?string $referenceId = null,
        public readonly ?string $organizationId = null,
        public readonly array $channels = ['database', 'broadcast', 'fcm'],
    ) {
        // Enqueue only after the surrounding DB transaction commits, so a push or
        // email failure can never roll back the payment that triggered this.
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return array_map(
            fn (string $c) => match ($c) {
                'fcm' => FcmChannel::class,
                'sms' => SmsChannel::class,
                default => $c,
            },
            $this->channels,
        );
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return $this->payload();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        // Reaches the app once it speaks Pusher/Reverb; same shape as REST.
        return new BroadcastMessage($this->payload());
    }

    /** Consumed by FcmChannel to build the push. @return array<string, mixed> */
    public function toFcm(object $notifiable): array
    {
        return $this->payload();
    }

    /**
     * The SMS body: the message alone, deliberately WITHOUT the title.
     *
     * SMS is billed per 160-character GSM-7 segment, and every message this
     * platform sends already restates its own subject ("Your booking is
     * confirmed and paid."). Prefixing the title would duplicate that and push
     * routine notifications over the segment boundary — doubling the bill on the
     * only channel that reaches 6,808 users.
     */
    public function toSms(object $notifiable): string
    {
        return $this->message;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return $this->payload();
    }

    /**
     * The single shape stored and sent everywhere. Keys are camelCase to match
     * the app's NotificationModel.fromJson without any transform layer.
     *
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'type' => $this->type->value,
            'title' => $this->title,
            'message' => $this->message,
            'referenceId' => $this->referenceId,
            'organizationId' => $this->organizationId,
        ];
    }
}
