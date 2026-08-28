<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Services\Notifications\SmsSender;
use Illuminate\Notifications\Notification;

/**
 * Delivers a notification as an SMS. Shaped exactly like FcmChannel so the two
 * behave the same from the notification's point of view: opt in by declaring
 * 'sms' in the channel list and implementing toSms().
 *
 * Recipient resolution order: the notifiable's routeNotificationForSms() if it
 * defines one, then its `phone` column. A notifiable with neither is skipped
 * silently — a user with no phone is normal, not an error.
 *
 * Best-effort by contract (SmsSender never throws): this channel runs inside the
 * queued PlatformNotification job, and Laravel sends channels in sequence, so a
 * throw here would abandon every channel listed after 'sms' — the in-app record
 * included. Failing to text someone must never also lose their notification row.
 */
class SmsChannel
{
    public function __construct(private readonly SmsSender $sender)
    {
    }

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toSms')) {
            return;
        }

        $message = (string) $notification->toSms($notifiable);
        if ($message === '') {
            return;
        }

        $this->sender->send($this->recipient($notifiable, $notification), $message);
    }

    private function recipient(object $notifiable, Notification $notification): ?string
    {
        if (method_exists($notifiable, 'routeNotificationFor')) {
            $routed = $notifiable->routeNotificationFor('sms', $notification);
            if (is_string($routed) && $routed !== '') {
                return $routed;
            }
        }

        // Falls back to users.phone, which is where every phone in this system
        // lives; SmsSender rejects anything that isn't a real Kenyan MSISDN.
        return isset($notifiable->phone) ? (string) $notifiable->phone : null;
    }
}
