<?php

declare(strict_types=1);

namespace App\Services\Loyalty;

use RuntimeException;

/**
 * The passenger cannot afford the redemption — thrown, not returned, so the
 * surrounding transaction rolls back.
 *
 * DB::transaction() commits whenever its closure returns normally. The QR scan
 * path writes its `qrcode_payments` receipt BEFORE attempting the debit, because
 * the ledger row keys on that receipt's id — so returning a refusal from inside
 * the closure committed the receipt anyway and left an orphan row claiming a ride
 * had been paid for that nobody paid for. Only an exception unwinds it.
 *
 * It never reaches a client: redeemForVehicle catches it and returns the same
 * 422 the booking path returns for the same condition.
 */
final class InsufficientPointsException extends RuntimeException
{
}
