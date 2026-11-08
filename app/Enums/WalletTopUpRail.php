<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a top-up's money is collected -- the app's `source`.
 *
 * The two rails behave DIFFERENTLY and the difference is not cosmetic:
 *
 *   Mpesa  Safaricom Daraja STK on a Komiut paybill. Has a callback, so the
 *          outcome arrives unprompted.
 *   Ncba   NCBA Till STK on paybill 880100. Has NO CALLBACK AT ALL -- NCBA's
 *          spec learns the result by querying (see NcbaStkClient), which is
 *          why PollNcbaStkPush exists. Anything written to assume a callback
 *          here will wait forever.
 */
enum WalletTopUpRail: string
{
    case Mpesa = 'mpesa';
    case Ncba = 'ncba';

    /** Whether this rail tells us the outcome without being asked. */
    public function hasCallback(): bool
    {
        return $this === self::Mpesa;
    }

    public static function tryParse(?string $value): ?self
    {
        $value = strtolower(trim((string) $value));

        return $value === '' ? null : self::tryFrom($value);
    }
}
