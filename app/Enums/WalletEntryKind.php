<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a wallet ledger row records -- the `kind` field on the wire.
 *
 * These five values are the app's `WalletEntryKind`, which falls back to
 * `adjustment` for an unknown string. That fallback is why a new kind must be
 * added to the app before the backend starts emitting it: otherwise a fare
 * would render to the passenger as an unexplained "adjustment".
 *
 * DIRECTION IS NOT ENCODED HERE. It is the sign of `amount_cents` on the row,
 * and the wire sends it as a separate `direction` field. A refund is a credit
 * and a fare is a debit today, but tying direction to kind would make a
 * corrective reversal of a refund unrepresentable.
 */
enum WalletEntryKind: string
{
    /** Money loaded in. */
    case TopUp = 'top_up';

    /** A ride paid for out of the balance. */
    case Fare = 'fare';

    /** A fare given back -- no-show, cancelled trip, overcharge. */
    case Refund = 'refund';

    /** An earlier entry undone because it should never have happened. */
    case Reversal = 'reversal';

    /** A manual correction by an operator. Always carries a reason. */
    case Adjustment = 'adjustment';

    /** The human label the app shows when the backend sends no `title`. */
    public function label(): string
    {
        return match ($this) {
            self::TopUp => 'Wallet top-up',
            self::Fare => 'Fare',
            self::Refund => 'Refund',
            self::Reversal => 'Reversal',
            self::Adjustment => 'Adjustment',
        };
    }
}
