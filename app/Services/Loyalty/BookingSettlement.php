<?php

declare(strict_types=1);

namespace App\Services\Loyalty;

use App\Enums\LoyaltyTransactionType;
use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\LoyaltyTransaction;
use Illuminate\Support\Collection;

/**
 * How a booking was actually settled, stated so that no screen can misread it.
 *
 * THE SCREEN THAT DID. The SACCO dashboard's bookings page showed a ride paid
 * with points as "Paid · Ksh 150" and counted the 150 into Revenue -- for a
 * ride on which no shilling changed hands (booking #8, NICCO, 2026-09-12).
 * The row carried `payment_method: "loyalty_points"`, but a client that reads
 * `paid` and `amount` has no reason to look further, and every client did
 * exactly that.
 *
 * And `payment_method` alone is not enough to look at, because it is stamped
 * from the RESERVE request -- the rail the app intended -- and until 2026-09-12
 * nothing rewrote it when M-Pesa actually settled the booking. The one reading
 * that cannot lie is the loyalty ledger: a `redeemed` row for the booking means
 * points paid for it, whatever the column says.
 *
 * So every booking a list returns carries three derived fields:
 *
 *   paid_with         "points" | "mpesa" | "cash" | ... | null   (null = not paid)
 *   points_spent      the points the ride cost, from the ledger; null otherwise
 *   amount_collected  KES the SACCO actually received for it: `amount` for a
 *                     money rail, 0 for points, 0 while unpaid
 *
 * Sum amount_collected for revenue. Show paid_with next to the status.
 */
final class BookingSettlement
{
    public const POINTS = 'points';

    /**
     * Attach the three fields to every booking in the collection, with ONE
     * ledger query for the page rather than one per row.
     *
     * @param  Collection<int, Booking>  $bookings
     */
    public static function annotate(Collection $bookings): Collection
    {
        if ($bookings->isEmpty()) {
            return $bookings;
        }

        // Unscoped: the ledger is the passenger's, and the caller here is a
        // SACCO admin or crew member reading their own bookings list. The value
        // is stored negative (a debit); the screen wants what it cost.
        $redeemed = LoyaltyTransaction::withoutGlobalScopes()
            ->whereIn('booking_id', $bookings->pluck('id')->all())
            ->where('type', LoyaltyTransactionType::Redeemed->value)
            ->pluck('value', 'booking_id')
            ->map(fn ($v) => round(abs((float) $v), 2));

        foreach ($bookings as $booking) {
            self::apply($booking, $redeemed->get((int) $booking->id));
        }

        return $bookings;
    }

    /** The same three fields on a single booking. */
    public static function annotateOne(Booking $booking): Booking
    {
        $spent = LoyaltyTransaction::withoutGlobalScopes()
            ->where('booking_id', $booking->id)
            ->where('type', LoyaltyTransactionType::Redeemed->value)
            ->value('value');

        return self::apply($booking, $spent === null ? null : round(abs((float) $spent), 2));
    }

    private static function apply(Booking $booking, ?float $pointsSpent): Booking
    {
        $paid = (bool) $booking->paid;
        $method = $booking->payment_method instanceof PaymentMethod
            ? $booking->payment_method->value
            : ($booking->payment_method !== null ? (string) $booking->payment_method : null);

        $paidWith = match (true) {
            ! $paid => null,
            $pointsSpent !== null => self::POINTS,
            $method === PaymentMethod::LoyaltyPoints->value => self::POINTS, // paid, no ledger row: legacy or import
            default => $method,
        };

        $booking->setAttribute('paid_with', $paidWith);
        $booking->setAttribute('points_spent', $paidWith === self::POINTS ? $pointsSpent : null);
        $booking->setAttribute('amount_collected', $paid && $paidWith !== self::POINTS ? round((float) $booking->amount, 2) : 0.0);

        return $booking;
    }
}
