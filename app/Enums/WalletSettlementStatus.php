<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The life of one payout to a SACCO.
 *
 * A ONE-WAY RATCHET: pending -> approved -> paid. A failure sends an approved
 * row back to approved (with a reason recorded) so it can be retried, and
 * nothing ever leaves `paid`.
 *
 * WHY APPROVAL IS A SEPARATE STATE rather than paying straight from the
 * accrual: the money is held on behalf of passengers, the amount is computed by
 * a sweep, and the first payout run after a bug would otherwise send real money
 * before anyone had looked at the number. Approval is the human in the loop,
 * and it is cheap to keep.
 */
enum WalletSettlementStatus: string
{
    /** Accrued by the sweep. Nobody has looked at it yet. */
    case Pending = 'pending';

    /** A human has agreed the figure. Payable. */
    case Approved = 'approved';

    /** Money has left. TERMINAL -- the row is immutable from here. */
    case Paid = 'paid';

    /** A payout was attempted and the rail refused. Retryable. */
    case Failed = 'failed';

    public function isPayable(): bool
    {
        return $this === self::Approved || $this === self::Failed;
    }

    /** Whether the figures may still change. */
    public function isSettled(): bool
    {
        return $this === self::Paid;
    }
}
