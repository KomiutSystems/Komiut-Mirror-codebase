<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a booking stopped being active. The distinction is not cosmetic — it
 * decides which channels the cancellation notification uses.
 *
 * Expiry is the ordinary end of an abandoned booking and happens in bulk:
 * bookings:release-expired runs every minute and app:check-passenger-payments
 * every two, both sweeping every unpaid booking on the platform. Texting each
 * one would bill an SMS credit per abandoned tap. A cancellation the passenger
 * did not cause, on a booking they had already PAID for, is the opposite: rare,
 * and worth the credit.
 */
enum BookingCancellationReason: string
{
    /** The unpaid hold ran past booking.hold_minutes and the seats were released. */
    case Expired = 'expired';

    /** Deliberately cancelled — by the passenger, the crew, or an operator. */
    case Cancelled = 'cancelled';

    /**
     * The crew marked the passenger as not boarded. Distinct from Cancelled
     * because it is the one reason that carries a REFUND: what they paid comes
     * back, and the notification has to say so.
     */
    case NoShow = 'no_show';

    /**
     * The trip ended or was cancelled before the passenger boarded -- by a
     * route the crew's own trip-end gate does not cover: the bus pulled out
     * of the queue, a new driver signed into it, the stale-queue sweep, the
     * dashboard, the last-stop pick-up, or a payment that landed after the
     * end. Carries a refund like NoShow, because the outcome for the
     * passenger is the same: paid, never rode.
     */
    case TripOver = 'trip_over';

    public function label(): string
    {
        return match ($this) {
            self::Expired => 'Booking expired',
            self::Cancelled => 'Booking cancelled',
            self::NoShow => 'Not boarded',
            self::TripOver => 'Trip ended before you boarded',
        };
    }

    /** Reasons on which what the passenger paid comes back. */
    public function refunds(): bool
    {
        return $this === self::NoShow || $this === self::TripOver;
    }
}
