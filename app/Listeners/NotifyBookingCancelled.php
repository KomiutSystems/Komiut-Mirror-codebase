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
 * The channel set is chosen by reason, and the reason is a cost decision:
 *
 *   Expired  — the ordinary end of an abandoned booking, produced in BULK.
 *              bookings:release-expired runs every minute and
 *              app:check-passenger-payments every two, each sweeping every
 *              unpaid booking on the platform. In-app + broadcast + push only:
 *              one SMS credit per abandoned tap, fleet-wide, is a bill nobody
 *              agreed to. An UNPAID passenger has also lost no money.
 *   Cancelled — deliberate, and rare. If the booking was PAID, the passenger is
 *              out real money and must be told on the only channel that reaches
 *              them, so SMS is added. An unpaid deliberate cancel is not worth a
 *              credit either.
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

        $channels = ['database', 'broadcast', 'fcm'];
        if (! $expired && $booking->paid) {
            $channels[] = 'sms';
        }

        $this->notifications->dispatch(
            $passenger,
            NotificationType::Trip,
            // Distinct titles on purpose: the dedupe key is (recipient,
            // referenceId, title), so a booking that expires and is later
            // cancelled outright is two different notifications, not one
            // swallowed by the other.
            $event->reason->label(),
            $expired
                ? sprintf('Booking #%s was not paid in time, so your seat has been released.', $ref)
                : sprintf('Booking #%s has been cancelled and your seat released.', $ref),
            $ref,
            channels: $channels,
        );
    }
}
