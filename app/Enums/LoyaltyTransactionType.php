<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ledger entry kinds. Sign convention is enforced by the LoyaltyService, not the
 * enum: Earned/Refunded are positive, Redeemed/Reversed are negative.
 */
enum LoyaltyTransactionType: string
{
    case Earned = 'earned';       // + credited for a paid ride
    case Redeemed = 'redeemed';   // − spent on a free ride
    case Reversed = 'reversed';   // − earn undone (e.g. refund of a paid ride)
    case Refunded = 'refunded';   // + points returned

    /**
     * Does this movement ADD to a balance?
     *
     * The sign belongs to the type and nowhere else. Every screen that renders a
     * ledger has to decide whether to draw + or −, and a client inferring it
     * from the string would get `reversed` wrong — it reads like an undo, and it
     * is a DEBIT: an earn being taken back when a paid ride is refunded.
     */
    public function isCredit(): bool
    {
        return match ($this) {
            self::Earned, self::Refunded => true,
            self::Redeemed, self::Reversed => false,
        };
    }

    /** How a person would describe this line on their statement. */
    public function label(): string
    {
        return match ($this) {
            self::Earned => 'Earned on a ride',
            self::Redeemed => 'Spent on a free ride',
            self::Reversed => 'Reversed — ride refunded',
            self::Refunded => 'Returned to your balance',
        };
    }
}
