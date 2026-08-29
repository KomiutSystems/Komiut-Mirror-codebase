<?php

declare(strict_types=1);

namespace App\Enums;

/** Ledger entry kinds. The sign lives on the row; this names the reason. */
enum CarbonCreditType: string
{
    case Earned = 'earned';       // + travelled far enough for a credit
    case Redeemed = 'redeemed';   // − spent on a reward
    case Refunded = 'refunded';   // + returned when a redemption is cancelled
    case Adjusted = 'adjusted';   // ± a platform correction, always explained

    public function label(): string
    {
        return match ($this) {
            self::Earned => 'Earned by travelling',
            self::Redeemed => 'Spent on a reward',
            self::Refunded => 'Returned to your balance',
            self::Adjusted => 'Adjusted by Komiut',
        };
    }
}
