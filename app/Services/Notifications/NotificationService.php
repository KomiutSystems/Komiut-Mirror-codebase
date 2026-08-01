<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\NotificationType;
use App\Models\User;
use App\Notifications\PlatformNotification;

/**
 * The one place notifications are sent. Callers describe WHAT happened; the
 * dispatcher decides persistence + realtime + push (via PlatformNotification's
 * channels) — no caller ever touches a channel directly. Mirrors the C#
 * single-dispatcher choke point.
 *
 * Idempotent on (recipient, referenceId, title): domain events retry (a webhook
 * redelivery, a re-run reconcile), and a driver must not get the same "new
 * booking" push twice. When no referenceId is given (e.g. a broadcast system
 * message) the guard is skipped — those are intentionally allowed to repeat.
 */
class NotificationService
{
    /**
     * @param  array<int, string>  $channels  any of: database, broadcast, fcm, mail
     */
    public function dispatch(
        User $user,
        NotificationType $type,
        string $title,
        string $message,
        ?string $referenceId = null,
        ?string $organizationId = null,
        array $channels = ['database', 'broadcast', 'fcm'],
    ): void {
        if ($referenceId !== null && $this->alreadySent($user, $referenceId, $title)) {
            return;
        }

        $user->notify(new PlatformNotification(
            type: $type,
            title: $title,
            message: $message,
            referenceId: $referenceId,
            organizationId: $organizationId,
            channels: $channels,
        ));
    }

    private function alreadySent(User $user, string $referenceId, string $title): bool
    {
        return $user->notifications()
            ->where('data->referenceId', $referenceId)
            ->where('data->title', $title)
            ->exists();
    }
}
