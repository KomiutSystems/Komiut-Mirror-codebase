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

    public function label(): string
    {
        return match ($this) {
            self::Expired => 'Booking expired',
            self::Cancelled => 'Booking cancelled',
        };
    }
}
