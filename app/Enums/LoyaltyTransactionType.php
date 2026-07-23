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
}
