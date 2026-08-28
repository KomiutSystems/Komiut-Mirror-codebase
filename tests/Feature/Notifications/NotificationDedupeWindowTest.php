<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\NotificationType;
use App\Models\User;
use App\Notifications\PlatformNotification;
use App\Services\Notifications\NotificationService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The dedupe guard is (recipient, referenceId, title) AND a time window.
 *
 * The window is the whole point. Without one the guard did not mean "swallow a
 * retry", it meant "never send this again as long as the row exists" — so any
 * state a booking can RE-ENTER was notified exactly once, forever, and silently.
 * Book a trip, let the hold lapse (bookings:release-expired runs every minute),
 * book the same trip again: the second "Booking created" was dropped and nothing
 * anywhere recorded that it had been.
 *
 * These tests pin both halves: retries still collapse, a genuine second lap does
 * not.
 */
final class NotificationDedupeWindowTest extends QueueTestCase
{
    private function dispatcher(): NotificationService
    {
        return app(NotificationService::class);
    }

    /**
     * Persist an in-app row directly, optionally aged. The real dispatch is
     * ShouldQueue + afterCommit and never lands under RefreshDatabase's open
     * transaction, so the guard has to be tested against rows written here.
     */
    private function give(User $user, string $title, string $ref, int $minutesAgo = 0): DatabaseNotification
    {
        $row = $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => PlatformNotification::class,
            'data' => [
                'type' => NotificationType::Trip->value,
                'title' => $title,
                'message' => 'body',
                'referenceId' => $ref,
            ],
            'read_at' => null,
        ]);

        if ($minutesAgo > 0) {
            // Mass update: timestamps must be forced, and no model events wanted.
            DatabaseNotification::whereKey($row->id)
                ->update(['created_at' => now()->subMinutes($minutesAgo)]);
        }

        return $row;
    }

    #[Test]
    public function a_retry_inside_the_window_is_still_swallowed(): void
    {
        // The behaviour worth keeping: payments:reconcile runs every 2 minutes
        // and an M-Pesa webhook can redeliver, so the same confirmation must not
        // reach the passenger twice.
        Notification::fake();
        $user = $this->makeUser();
        $this->give($user, 'Booking confirmed', '5', minutesAgo: 1);

        $this->dispatcher()->dispatch($user, NotificationType::Trip, 'Booking confirmed', 'msg', '5');

        Notification::assertNothingSentTo($user);
    }

    #[Test]
    public function the_same_notification_sends_again_once_the_window_has_passed(): void
    {
        // The bug this fixes. Same recipient, same booking id, same title — but
        // a genuine second visit to that state, long after any retry chain could
        // still be running.
        Notification::fake();
        $user = $this->makeUser();
        $window = (int) config('notifications.dedupe_minutes', 30);
        $this->give($user, 'Booking created', '5', minutesAgo: $window + 1);

        $this->dispatcher()->dispatch($user, NotificationType::Trip, 'Booking created', 'msg', '5');

        Notification::assertSentTo(
            $user,
            PlatformNotification::class,
            fn (PlatformNotification $n) => $n->title === 'Booking created' && $n->referenceId === '5'
        );
    }

    #[Test]
    public function a_different_title_on_the_same_booking_is_never_deduped(): void
    {
        // Why the lifecycle titles are distinct strings: 'Booking created',
        // 'Booking confirmed', 'Booking expired' and 'Booking cancelled' all
        // carry the same referenceId, and every one of them must land.
        Notification::fake();
        $user = $this->makeUser();
        $this->give($user, 'Booking created', '5');

        $this->dispatcher()->dispatch($user, NotificationType::Trip, 'Booking cancelled', 'msg', '5');

        Notification::assertSentTo(
            $user,
            PlatformNotification::class,
            fn (PlatformNotification $n) => $n->title === 'Booking cancelled'
        );
    }

    #[Test]
    public function another_users_matching_notification_does_not_suppress_mine(): void
    {
        // The guard is per recipient. A driver and a passenger are both notified
        // about the same booking id; one must not silence the other.
        Notification::fake();
        $other = $this->makeUser();
        $this->give($other, 'Booking created', '5');
        $mine = $this->makeUser();

        $this->dispatcher()->dispatch($mine, NotificationType::Trip, 'Booking created', 'msg', '5');

        Notification::assertSentTo($mine, PlatformNotification::class);
    }

    #[Test]
    public function a_notification_with_no_reference_id_always_sends(): void
    {
        // Broadcast/system messages carry no reference, so there is nothing to
        // be idempotent about — they are intentionally allowed to repeat.
        Notification::fake();
        $user = $this->makeUser();
        $this->give($user, 'Service notice', '');

        $this->dispatcher()->dispatch($user, NotificationType::System, 'Service notice', 'msg');

        Notification::assertSentTo($user, PlatformNotification::class);
    }
}
