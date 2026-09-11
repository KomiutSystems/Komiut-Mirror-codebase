<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\BookingCancellationReason;
use App\Enums\NotificationType;
use App\Events\BookingCancelled;
use App\Models\User;
use App\Services\Notifications\NotificationService;

/**
 * On a cancelled/expired booking: tell the passenger their seats went back on
 * sale, so the silence they used to get is no longer indistinguishable from
 * still holding a seat.
 *
 * Every reason goes out on the same three channels -- database, broadcast,
 * push -- and NONE of them texts. The channel set used to be chosen by reason,
 * with SMS added for a deliberately cancelled PAID booking on the argument that
 * someone out real money should not have to open the app to find out; that is
 * no longer how this platform notifies, and no branch below adds a channel.
 *
 * What the reason DOES choose is the wording:
 *
 *   Expired  — the ordinary end of an abandoned unpaid hold, produced in BULK
 *              by bookings:release-expired (every minute) and
 *              app:check-passenger-payments (every two).
 *   Cancelled — deliberate: the passenger, the crew, or an operator.
 *   NoShow   — the crew marked the passenger not boarded. The only reason that
 *              can carry a refund, and whether it DID is read off the event's
 *              `refunded` figure, never assumed from the reason: the refund has
 *              real null exits (unpaid, no program to price a ride credit, a
 *              ledger failure the caller swallowed), and a passenger told their
 *              money is back when it is not has been lied to about money.
 */
class NotifyBookingCancelled
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(BookingCancelled $event): void
    {
        $booking = $event->booking;

        $passenger = User::find($booking->user_id);
        if ($passenger === null) {
            return;
        }

        $ref = (string) $booking->id;
        $expired = $event->reason === BookingCancellationReason::Expired;
        $noShow = $event->reason === BookingCancellationReason::NoShow;
        // A positive figure, not merely non-null: the caller passes whatever the
        // ledger returned, and a zero-value row would read as "0 points have
        // been returned", which is the same lie with a number on it.
        $refunded = $noShow && $event->refunded !== null && $event->refunded > 0;

        // IN-APP ONLY, NO SMS. A deliberately cancelled PAID booking used to be
        // texted as well, on the argument that someone who has parted with money
        // and lost the seat should not have to open the app to learn it. That is
        // still true of the situation; it is simply not how this platform
        // notifies any more. The row, the socket and the push carry it.
        $channels = ['database', 'broadcast', 'fcm'];

        $this->notifications->dispatch(
            $passenger,
            NotificationType::Trip,
            // Distinct titles on purpose: the dedupe key is (recipient,
            // referenceId, title), so a booking that expires and is later
            // cancelled outright is two different notifications, not one
            // swallowed by the other.
            $event->reason->label(),
            match (true) {
                $expired => sprintf('Booking #%s was not paid in time, so your seat has been released.', $ref),
                // The one cancellation that can carry a refund, and the passenger
                // must hear that half of it -- a bare "cancelled" on a ride they
                // paid for reads as theft. But ONLY when it happened. This used
                // to promise "what you paid has been returned" on every no-show,
                // including the ones where refundForBooking() returned null (a
                // money-paid seat on a SACCO with no loyalty program, for one)
                // and the balance had not moved. The figure comes from the
                // ledger row the caller was handed, so the message and the
                // balance cannot disagree.
                $refunded => sprintf(
                    'You were not boarded on booking #%s. %s points have been returned to your balance.',
                    $ref, $this->points((float) $event->refunded),
                ),
                $noShow => sprintf('You were not boarded on booking #%s and your seat has been released.', $ref),
                default => sprintf('Booking #%s has been cancelled and your seat released.', $ref),
            },
            $ref,
            channels: $channels,
        );
    }

    /** 5.0 reads as "5", 7.5 as "7.5": a points figure, not a money one. */
    private function points(float $value): string
    {
        $text = number_format($value, 2, '.', '');

        return rtrim(rtrim($text, '0'), '.');
    }
}
