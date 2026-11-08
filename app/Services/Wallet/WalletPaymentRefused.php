<?php

declare(strict_types=1);

namespace App\Services\Wallet;

use RuntimeException;

/**
 * A fare refused by a check that only holds under the row lock.
 *
 * THROWN RATHER THAN RETURNED, and that distinction is the whole reason this
 * class exists. DB::transaction() COMMITS whenever its closure returns
 * normally, so returning an error array from inside one leaves everything
 * written before it behind -- which on the QR path means an orphan
 * `qrcode_payments` receipt claiming a ride that nobody paid for. Only an
 * exception rolls it back. redeemForVehicle has the same note in as many words,
 * having been the place it was found.
 *
 * Separate from WalletRefusal because these are not the app's coded refusals:
 * there is no `code` for "the trip ended", the client has nothing to branch on,
 * and the message is the whole payload.
 */
final class WalletPaymentRefused extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
