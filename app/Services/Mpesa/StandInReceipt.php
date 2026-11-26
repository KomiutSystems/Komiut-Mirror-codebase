<?php

declare(strict_types=1);

namespace App\Services\Mpesa;

/**
 * What goes in a receipt's `transid` when a payment was settled without
 * learning its M-Pesa code.
 *
 * The receipt tables hold transid UNIQUE and NOT NULL, so a settlement that
 * knows the money moved but not the code -- STK Push Query returns no code
 * (payments:reconcile), NCBA's query neither (NcbaStkSettlement, until its
 * C2B confirmation lands) -- writes a value unique to the push instead. Those
 * values are bookkeeping, not codes: M-Pesa support cannot find one, so
 * nothing a passenger reads or is pushed may show one.
 *
 * The prefixes live HERE, once, because the writers and the readers used to
 * keep their own lists. PassengerPaymentFeed knew `ws_CO_` and `recon-` and
 * not NCBA's, so an NCBA fare's receipt sheet and push read "NCBA-…".
 */
final class StandInReceipt
{
    /** A Daraja CheckoutRequestID, stored as-is by the reconcile. */
    public const DARAJA_CHECKOUT = 'ws_CO_';

    /** The reconcile's last resort when even the checkout id is missing. */
    public const RECONCILE = 'recon-';

    /** NCBA's TransactionID, before its C2B confirmation names the receipt. */
    public const NCBA = 'NCBA-';

    public const PREFIXES = [self::DARAJA_CHECKOUT, self::RECONCILE, self::NCBA];

    public static function forNcba(string $transactionId): string
    {
        return self::NCBA.$transactionId;
    }

    public static function forReconcile(int $pushId): string
    {
        return self::RECONCILE.$pushId;
    }

    /**
     * Whether $transid is a stand-in rather than an M-Pesa code. The push's
     * own checkout id counts whatever it looks like: a bank's reference is
     * not a Safaricom one, and the reconcile stores whichever id it had.
     */
    public static function is(?string $transid, ?string $checkoutId = null): bool
    {
        $transid = trim((string) $transid);
        if ($transid === '' || ($checkoutId !== null && $transid === $checkoutId)) {
            return true;
        }

        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($transid, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
