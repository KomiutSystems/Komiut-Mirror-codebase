<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Events\BookingPaid;
use App\Models\User;
use App\Notifications\PlatformNotification;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The catalog wiring: paying a booking notifies the passenger (and, when there
 * is one, the assigned driver). Verified via a faked notifier so no channel I/O
 * runs — the point is that BookingPaid reaches NotifyBookingConfirmed.
 */
final class BookingPaidNotifiesTest extends QueueTestCase
{
    #[Test]
    public function paying_a_booking_notifies_the_passenger(): void
    {
        Notification::fake();
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $passenger = $this->makeUser([], $world['sacco']);
        $booking = $this->makeBooking($queue, $passenger, $world['from'], $world['to'], 'Rider');

        BookingPaid::dispatch($booking);

        Notification::assertSentTo(
            $passenger,
            PlatformNotification::class,
            fn (PlatformNotification $n) => $n->title === 'Booking confirmed' && $n->referenceId === (string) $booking->id
        );
    }
}
