<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\MpesaQrcodePayment;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Tells a money row which rail it actually arrived on.
 *
 * A QR payment leaves TWO independent records. The STK callback writes
 * `qrcode_payments` + `mpesa_qrcode_payments`; the money itself lands on the
 * till and is written separately by the C2B confirmation into `mpesas` +
 * `transactions` + `summaries`. Nothing joined the two, so /transactions and
 * /transactions/mpesa rendered a scanned QR payment as an ordinary till
 * payment and the QR screen was a silo nobody cross-checked. The link is
 * `mpesa_qrcode_payments.transid = mpesas.TransID` — the same Safaricom
 * receipt number, unique on both sides.
 *
 * Two things this class deliberately does NOT do:
 *
 *  - It does not sum anything. QR is a SUBSET of the M-Pesa rail, not a fourth
 *    rail beside mpesa/cash: the money is already in the `mpesa` total on
 *    /transactions. A "qr" tile added next to those two would be double
 *    counting real Kenyan shillings on the screen operators reconcile against.
 *  - It does not add an index. `mpesa_qrcode_payments.transid` is already
 *    UNIQUE (2024_04_09_063854_create_mpesa_qrcode_payments_table) and
 *    `mpesas.TransID` is both UNIQUE and separately indexed, so every lookup
 *    below is an index probe. Both tables are empty today, but the join column
 *    was already covered — adding a duplicate index would be pure write cost.
 */
final class PaymentSource
{
    /** Scanned by the passenger: STK push against the vehicle's QR code. */
    public const QR = 'qr';

    /** The plain till/paybill rail — a C2B confirmation with no QR record. */
    public const MPESA = 'mpesa';

    /** Conductor-recorded cash. */
    public const CASH = 'cash';

    /**
     * Stand-in for a `?source=` that is not even a string — `?source[]=qr`
     * arrives as an array. Returning null there would mean "no filter asked
     * for" and quietly serve every rail; this makes isKnown() say no, so the
     * caller rejects it. Deliberately a token no rail can ever equal.
     */
    private const NOT_A_RAIL = '__not_a_rail__';

    /**
     * The values `?source=` accepts.
     *
     * @return list<string>
     */
    public static function filters(): array
    {
        return [self::QR, self::MPESA, self::CASH];
    }

    /**
     * Read `?source=` off a request value.
     *
     * Case- and whitespace-insensitive on purpose. A dashboard that sends "QR"
     * must not silently fall through to "no filter applied" and hand the
     * operator every till payment on a screen labelled QR — that is a wrong
     * money screen, not a cosmetic bug. Returns null when the caller asked for
     * nothing, so the listing behaves exactly as it did before this parameter
     * existed. Anything else comes back for the caller to run past isKnown()
     * and reject.
     */
    public static function normalise(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) && ! is_numeric($value)) {
            return self::NOT_A_RAIL;
        }

        $source = strtolower(trim((string) $value));

        return $source === '' ? null : $source;
    }

    /** Is this one of the rails a listing knows how to narrow to? */
    public static function isKnown(string $source): bool
    {
        return in_array($source, self::filters(), true);
    }

    /**
     * Which of these M-Pesa receipts came in through a QR scan.
     *
     * ONE query for the WHOLE page, never one per row. /transactions and
     * /transactions/mpesa page 20 rows at a time over a 1.3M-row table; a
     * per-row lookup would add 20 round trips to every page turn of the busiest
     * screen in the product, and the cost would grow with the page size the
     * moment anybody raised it.
     *
     * @param  array<int, mixed>  $transIds  receipts off the page (nulls welcome — cash rows have none)
     * @return array<string, true>  receipt => true, so callers test with isset()
     */
    public static function qrReceipts(array $transIds): array
    {
        $receipts = [];
        foreach ($transIds as $transId) {
            if (! is_string($transId) && ! is_numeric($transId)) {
                continue;
            }
            $receipt = trim((string) $transId);
            if ($receipt !== '') {
                $receipts[$receipt] = true;
            }
        }

        if ($receipts === []) {
            return [];
        }

        // withoutGlobalScopes is NOT used and is NOT needed: MpesaQrcodePayment
        // carries no SaccoScope/BrandScope. The rows this can reach are already
        // limited to the receipts on a page the caller was allowed to see.
        return array_fill_keys(
            MpesaQrcodePayment::query()
                ->whereIn('transid', array_keys($receipts))
                ->pluck('transid')
                ->all(),
            true
        );
    }

    /**
     * The source of one already-loaded row.
     *
     * @param  mixed  $receipt  the row's M-Pesa receipt, or null when it has none
     * @param  array<string, true>  $qrReceipts  the page's set, from qrReceipts()
     * @param  string|null  $rail  what this row is when it is not a QR payment
     */
    public static function forReceipt(mixed $receipt, array $qrReceipts, ?string $rail): ?string
    {
        if (! is_string($receipt) && ! is_numeric($receipt)) {
            return $rail;
        }

        $receipt = trim((string) $receipt);

        return $receipt !== '' && isset($qrReceipts[$receipt]) ? self::QR : $rail;
    }

    /**
     * Narrow a query to rows whose receipt column either is, or is not, a QR payment.
     *
     * EXISTS rather than IN/NOT IN. `NOT IN (subquery)` returns ZERO rows the
     * moment one NULL appears in the subquery, which on this path would blank
     * the entire till listing rather than fail loudly — and the reader would
     * have no reason to suspect the filter. NOT EXISTS has no such cliff.
     *
     * The caller is responsible for the rail itself: on /transactions,
     * `mpesas.TransID` is LEFT joined, so a cash row's NULL receipt satisfies
     * NOT EXISTS and would slip into ?source=mpesa without an accompanying
     * whereNotNull('transactions.mpesa_id').
     *
     * @param  EloquentBuilder|QueryBuilder  $query  the listing being narrowed, mutated in place
     * @param  string  $receiptColumn  qualified column holding the M-Pesa receipt, e.g. `mpesas.TransID`
     */
    public static function constrainQr(EloquentBuilder|QueryBuilder $query, string $receiptColumn, bool $isQr): void
    {
        $matchingQrRow = static function ($sub) use ($receiptColumn): void {
            $sub->selectRaw('1')
                ->from('mpesa_qrcode_payments')
                ->whereColumn('mpesa_qrcode_payments.transid', $receiptColumn);
        };

        if ($isQr) {
            $query->whereExists($matchingQrRow);

            return;
        }

        $query->whereNotExists($matchingQrRow);
    }
}
