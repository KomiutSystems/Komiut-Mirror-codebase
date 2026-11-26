<?php

declare(strict_types=1);

namespace App\Services\Payments\PassengerPayments;

use Carbon\CarbonInterface;

/**
 * What the wallet ledger says about one wallet-paid booking or QR fare: when
 * the fare was taken from the wallet, and when (if ever) it was given back.
 *
 * WHY THE LEDGER. A wallet payment stamps nothing on the booking or fare but
 * `paid` -- no receipt row, no paid time -- and a support refund
 * (WalletOpsService::refundFare) touches neither row at all. Read from the
 * rows alone, a wallet booking's paid time was its updated_at, which moved on
 * every later save (boarding, a refund), and a refunded wallet fare read
 * "paid" for ever.
 *
 * The ledger links them by reference, both unique and indexed: the fare entry
 * is `fare:booking:{booking id}` or `fare:tap:{qr fare id}`
 * (WalletPaymentService), its refund `refund:fare:{fare entry id}`
 * (WalletOpsService). PassengerPaymentFeed reads a page's trails in one query.
 */
final class WalletTrail
{
    public const BOOKING_FARE = 'fare:booking:';

    public const TAP_FARE = 'fare:tap:';

    public const REFUND = 'refund:fare:';

    public function __construct(
        public readonly ?CarbonInterface $paidAt,
        public readonly ?CarbonInterface $refundedAt,
    ) {}

    public static function referenceFor(string $kind, int $id): string
    {
        return ($kind === PassengerPayment::KIND_BOOKING ? self::BOOKING_FARE : self::TAP_FARE).$id;
    }

    /**
     * The same link as SQL, for ?status=: does the owner `o` (bookings or
     * qrcode_payments) have a refunded wallet fare?
     */
    public static function refundedSql(string $kind): string
    {
        $prefix = $kind === PassengerPayment::KIND_BOOKING ? self::BOOKING_FARE : self::TAP_FARE;

        return 'EXISTS (SELECT 1 FROM wallet_transactions wf'
            ." JOIN wallet_transactions wr ON wr.reference = '".self::REFUND."' || wf.id AND wr.kind = 'refund'"
            ." WHERE wf.reference = '{$prefix}' || o.id AND wf.kind = 'fare')";
    }
}
