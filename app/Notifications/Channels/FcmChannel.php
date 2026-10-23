<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Models\FirebaseToken;
use App\Services\Notifications\FcmSender;
use Illuminate\Notifications\Notification;

/**
 * Delivers a PlatformNotification as an FCM push to every device the recipient
 * has registered. Sends are best-effort and independent — one dead token does
 * not stop the others, and nothing here can throw into the queued job's caller.
 */
class FcmChannel
{
    public function __construct(private readonly FcmSender $sender)
    {
    }

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toFcm')) {
            return;
        }

        $payload = $notification->toFcm($notifiable);
        $userId = $notifiable->getKey();

        // Token AND locale: one person may carry an English phone and a Swahili
        // one, and each gets its own language.
        $devices = FirebaseToken::where('user_id', $userId)
            ->get(['firebase_token', 'locale'])
            ->unique('firebase_token');

        $localised = method_exists($notification, 'fcmFor');

        foreach ($devices as $device) {
            $text = $localised
                ? $notification->fcmFor($device->locale)
                : ['title' => $payload['title'] ?? '', 'message' => $payload['message'] ?? ''];

            $this->sender->send(
                (string) $device->firebase_token,
                (string) ($text['title'] ?? ''),
                (string) ($text['message'] ?? ''),
                [
                    // Keys the app's push handler reads for its deep-link.
                    'type' => (string) ($payload['type'] ?? 'system'),
                    'referenceId' => (string) ($payload['referenceId'] ?? ''),
                ],
            );
        }
    }
}
