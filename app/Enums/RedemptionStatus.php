<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A redemption is claimed instantly and fulfilled later, because no partner
 * hands over a bundle or a voucher synchronously. Credits leave the balance at
 * `pending` — otherwise the same credits could be claimed twice while the first
 * claim was still being fulfilled — and come back on `cancelled`.
 */
enum RedemptionStatus: string
{
    case Pending = 'pending';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Being processed',
            self::Fulfilled => 'Delivered',
            self::Cancelled => 'Cancelled — credits returned',
        };
    }
}
