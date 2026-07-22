<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The payment rail a passenger chooses for a booking. The backing values are
 * what the mobile app sends and what is stored on `bookings.payment_method`.
 */
enum PaymentMethod: string
{
    case Mpesa = 'mpesa';
    case NcbaTill = 'ncba_till';
    case CoopTill = 'coop_till';
    case Wallet = 'wallet';
    case LoyaltyPoints = 'loyalty_points';

    /** Rails that settle via an out-of-band callback (STK push et al.). */
    public function isMobileMoney(): bool
    {
        return in_array($this, [self::Mpesa, self::NcbaTill, self::CoopTill], true);
    }
}
