<?php

declare(strict_types=1);

namespace App\Services\Loyalty;

use App\Enums\LoyaltyTransactionType;
use App\Enums\WalletEntryKind;
use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\LoyaltyTransaction;
use App\Models\WalletTransaction;
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
 *   paid_with         "points" | "wallet" | "mpesa" | "cash" | ... | null
 *                     (null = not paid)
 *   points_spent      the points the ride cost, from the ledger; null otherwise
 *   amount_collected  KES the SACCO actually received for it: `amount` for a
 *                     money rail, 0 for points, 0 for WALLET, 0 while unpaid
 *   amount_pending_settlement
 *                     KES the SACCO has EARNED but not yet been paid: `amount`
 *                     for a wallet fare, 0 for everything else
 *
 * Sum amount_collected for revenue. Show paid_with next to the status.
 *
 * WHY A WALLET FARE COLLECTS NOTHING. It is real money, unlike points -- but it
 * landed in the Komiut float, not the SACCO's till (App\Services\Wallet\WalletFloat),
 * and it reaches the SACCO only when it is settled. Counting it as collected
 * would tell an operator their takings are in their account when they are not,
 * and their bank reconciliation would fail by the amount of every wallet ride.
 * So it is earned-but-unpaid, which is its own field rather than a footnote on
 * an existing one.
 */
final class BookingSettlement
{
    public const POINTS = 'points';

    public const WALLET = 'wallet';

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

        $ids = $bookings->pluck('id')->all();

        // Which of these were paid from a wallet, read from the WALLET ledger
        // for the same reason points are read from theirs: `payment_method` is
        // stamped from the reserve request -- the rail the app INTENDED -- and a
        // booking held with "wallet" in mind and then settled by M-Pesa would
        // otherwise read as a wallet fare and under-report the SACCO's takings.
        $walletPaid = array_flip(
            WalletTransaction::query()
                ->whereIn('booking_id', $ids)
                ->where('kind', WalletEntryKind::Fare->value)
                ->pluck('booking_id')
                ->map(fn ($id) => (int) $id)
                ->all()
        );

        // Unscoped: the ledger is the passenger's, and the caller here is a
        // SACCO admin or crew member reading their own bookings list. The value
        // is stored negative (a debit); the screen wants what it cost.
        $redeemed = LoyaltyTransaction::withoutGlobalScopes()
            ->whereIn('booking_id', $ids)
            ->where('type', LoyaltyTransactionType::Redeemed->value)
            ->pluck('value', 'booking_id')
            ->map(fn ($v) => round(abs((float) $v), 2));

        foreach ($bookings as $booking) {
            self::apply(
                $booking,
                $redeemed->get((int) $booking->id),
                isset($walletPaid[(int) $booking->id]),
            );
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

        $fromWallet = WalletTransaction::query()
            ->where('booking_id', $booking->id)
            ->where('kind', WalletEntryKind::Fare->value)
            ->exists();

        return self::apply($booking, $spent === null ? null : round(abs((float) $spent), 2), $fromWallet);
    }

    private static function apply(Booking $booking, ?float $pointsSpent, bool $fromWallet = false): Booking
    {
        $paid = (bool) $booking->paid;
        $method = $booking->payment_method instanceof PaymentMethod
            ? $booking->payment_method->value
            : ($booking->payment_method !== null ? (string) $booking->payment_method : null);

        $paidWith = match (true) {
            ! $paid => null,
            // The LEDGERS win over the column, points first: a ride settled with
            // points and one settled from a wallet both leave a row, and a row
            // cannot be stale the way payment_method can.
            $pointsSpent !== null => self::POINTS,
            $fromWallet => self::WALLET,
            $method === PaymentMethod::LoyaltyPoints->value => self::POINTS, // paid, no ledger row: legacy or import
            default => $method,
        };

        // Neither points nor wallet money reaches the SACCO's till, so neither is
        // revenue they can bank today. See the class docblock.
        $collected = $paid && $paidWith !== self::POINTS && $paidWith !== self::WALLET;

        $booking->setAttribute('paid_with', $paidWith);
        $booking->setAttribute('points_spent', $paidWith === self::POINTS ? $pointsSpent : null);
        $booking->setAttribute('amount_collected', $collected ? round((float) $booking->amount, 2) : 0.0);
        $booking->setAttribute(
            'amount_pending_settlement',
            $paidWith === self::WALLET ? round((float) $booking->amount, 2) : 0.0,
        );

        return $booking;
    }
}
