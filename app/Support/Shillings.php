<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The one place shillings become cents and back.
 *
 * WHY CENTS AT ALL, when every rail here deals in whole shillings and the app's
 * entire wallet contract is integer KES: because `transactions.amount` in this
 * schema is a `double`, and a float is the wrong type for a number you
 * decrement. Doubles cannot represent 0.1 exactly, so repeated debits
 * accumulate drift -- fine for a reporting column that is only ever summed,
 * fatal for a balance a passenger spends down to zero. The wallet stores
 * integers and never divides, so its arithmetic is exact.
 *
 * The boundary is one-directional on purpose:
 *
 *   in   toCents()     -- rejects anything that is not a whole shilling
 *   out  toShillings() -- FLOORS
 *
 * Flooring on the way out is a money decision, not a rounding preference: a
 * balance of 150.99 must display as 150, never 151. Showing a passenger a
 * shilling they cannot spend produces a refusal they cannot explain, and
 * rounding UP a balance is the one error that makes the app lie in the
 * passenger's favour and then take it back at the door.
 */
final class Shillings
{
    public const PER_SHILLING = 100;

    /**
     * Whole shillings in, cents out.
     *
     * Rejects fractions rather than silently truncating them. Every caller is
     * either a validated request body (integer) or a config value (integer), so
     * a fraction arriving here means a bug upstream, and a wallet is the wrong
     * place to absorb one quietly.
     *
     * @throws \InvalidArgumentException
     */
    public static function toCents(int|float $shillings): int
    {
        if (is_float($shillings) && abs($shillings - round($shillings)) > 0.000001) {
            throw new \InvalidArgumentException('Wallet amounts are whole shillings; got '.$shillings);
        }

        return (int) round($shillings) * self::PER_SHILLING;
    }

    /**
     * A stored money amount in cents, rounded to the nearest CENT.
     *
     * For `bookings.amount` and other columns that are NOT guaranteed whole
     * shillings. toCents() refuses a fraction on purpose -- it guards the inputs
     * a passenger or a config supplies -- but a fare already written to the
     * database has to be charged as it stands, and rounding it to the nearest
     * SHILLING first was losing up to 50 cents a ride: the passenger was
     * debited the rounded figure while the SACCO was accrued the unrounded one,
     * so the float went short (or the passenger overpaid) on every fractional
     * fare.
     */
    public static function fromAmount(int|float $amount): int
    {
        return (int) round(((float) $amount) * self::PER_SHILLING);
    }

    /** Cents in, whole shillings out, always rounded DOWN. See the class note. */
    public static function toShillings(int $cents): int
    {
        return intdiv($cents, self::PER_SHILLING);
    }
}
