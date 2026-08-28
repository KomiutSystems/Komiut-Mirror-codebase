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
 * Idempotent on (recipient, referenceId, title) WITHIN A TIME WINDOW: domain
 * events retry (a webhook redelivery, a re-run reconcile), and a driver must not
 * get the same "new booking" push twice. When no referenceId is given (e.g. a
 * broadcast system message) the guard is skipped — those are intentionally
 * allowed to repeat.
 *
 * The window is the fix for a real silent-failure mode. The guard used to have
 * none, so it did not mean "don't repeat a retry", it meant "never send this
 * (recipient, reference, title) again as long as the row exists". Any state a
 * booking can RE-ENTER was therefore notified exactly once, ever: book → hold
 * lapses (bookings:release-expired runs every minute) → book the same trip again
 * and the second "Booking created" never went out, with nothing logged to say
 * so. Scoping the lookup to config('notifications.dedupe_minutes') keeps retry
 * idempotency and gives the state machine its second lap back.
 */
class NotificationService
{
    /**
     * @param  array<int, string>  $channels  any of: database, broadcast, fcm, sms, mail
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
        // created_at bound, not just the (reference, title) match: see the class
        // docblock. Only the database channel writes these rows, so a
        // notification dispatched WITHOUT 'database' in its channels is never
        // deduped by this at all — that is deliberate, there is nothing to
        // compare against, and an SMS-only alert repeating is a smaller harm
        // than one that never arrives.
        $window = (int) config('notifications.dedupe_minutes', 30);

        return $user->notifications()
            ->where('data->referenceId', $referenceId)
            ->where('data->title', $title)
            ->where('created_at', '>=', now()->subMinutes($window))
            ->exists();
    }
}
