<?php

declare(strict_types=1);

namespace App\Enums;

/** Who honours a reward. Fulfilment differs per partner; the ledger does not. */
enum RewardPartner: string
{
    case Safaricom = 'safaricom';     // airtime or a data bundle
    case Sacco = 'sacco';             // a free ride, funded by the SACCO
    case Supermarket = 'supermarket'; // a shopping voucher
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Safaricom => 'Safaricom',
            self::Sacco => 'SACCO free ride',
            self::Supermarket => 'Supermarket',
            self::Other => 'Partner',
        };
    }
}
