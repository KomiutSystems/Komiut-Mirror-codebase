<?php

namespace App\Http\Controllers\APIs\Dashboard\Transactions;

use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Concerns\ResolvesDateRange;
use App\Http\Controllers\Concerns\ScopesToOwnedVehicles;
use App\Http\Controllers\Concerns\SeeksByCursor;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\Payments\PaymentSource;
use App\Services\Sql\LikeSql;
use App\Services\Sql\PlateSql;
use App\Support\TransDate;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransactionsAPIController extends Controller
{
    use PaginatesResults;
    use ResolvesDateRange;
    use ScopesToOwnedVehicles;
    use SeeksByCursor;

    /**
     * Rows one download may contain: platform.exports.{csv,pdf}_max_rows.
     *
     * An export is unpaginated by design — a CSV of page one is not an export —
     * so the only thing bounding it is the date window the caller chose, and a
     * careless year-long range on a busy SACCO would otherwise try to stream a
     * million rows into a spreadsheet nobody can open. Over the cap the export
     * is refused with the count, so the caller narrows the range. It is never
     * cut short: this used to `limit(20000)` and write a TOTAL under whatever
     * fitted, and NCBA's one-day file carried 20,000 of 27,671 payments with
     * nothing to say so.
     */
    private function exportMaxRows(string $format): int
    {
        return max(1, (int) config("platform.exports.{$format}_max_rows", 20000));
    }

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function getTransactions(Request $request)
    {
        $page = max(intval($request->page ?? 1), 1) - 1;
        $per = $this->perPage($request, 20, self::LEDGER_MAX_PER_PAGE);
        $offset = $page * $per;

        // An unrecognised ?source is REJECTED, not ignored. Ignoring it would
        // hand back every rail under a heading the operator chose to mean one
        // rail — the money real, the label wrong. `source` is a new parameter,
        // so nothing already on the dashboard can trip this.
        $source = PaymentSource::normalise($request->input('source'));
        if ($source !== null && ! PaymentSource::isKnown($source)) {
            return response()->json([
                'error' => 'Unknown source. Expected one of: '.implode(', ', PaymentSource::filters()).'.',
            ], 400);
        }

        [$from_date, $to_date] = $this->dateRange($request);
        $transactions = $this->baseQuery($request, $from_date, $to_date);

        // BEFORE the totals and the pager below, so the two figures beside the
        // table always describe the rows in it. A filtered list under an
        // unfiltered total is the exact shape of a reconciliation dispute.
        $this->narrowToSource($transactions, $source);

        // The SAME filter, reached the other way round -- see baseQuery. The
        // list below stops at twenty rows; the count and the tiles cannot stop
        // at all, and the shape that is cheapest for one is ruinous for the
        // other. Both must still describe the same set, which is what
        // TransactionSearchTotalsTest checks on every search it knows.
        $totals = $this->baseQuery($request, $from_date, $to_date, forTotals: true);
        $this->narrowToSource($totals, $source);

        // The two totals beside the table are SUMs over the WHOLE filtered set,
        // so unlike the twenty rows below them they have no LIMIT to stop at and
        // cost far more than the page does. Cached on the same key as the count,
        // tagged apart so the mpesa and cash figures cannot collide.
        $mpesaSum = $this->cachedScalar(
            $totals,
            'txn:mpesa',
            fn () => (clone $totals)->whereNotNull('transactions.mpesa_id')->sum('transactions.amount')
        );
        $cashSum = $this->cachedScalar(
            $totals,
            'txn:cash',
            fn () => (clone $totals)->whereNotNull('transactions.cash_id')->sum('transactions.amount')
        );
        // THE THIRD TILE, so the tiles account for every row in the table.
        // Without it a day with wallet taps showed M-Pesa + cash beside a table
        // and an export TOTAL that were both larger, with no column explaining
        // the difference.
        $walletSum = $this->cachedScalar(
            $totals,
            'txn:wallet',
            fn () => (clone $totals)->whereNotNull('transactions.wallet_transaction_id')->sum('transactions.amount')
        );

        // Page metadata. This endpoint returned rows, an mpesa total and a cash
        // total, and nothing about paging — so the client had no way to know
        // whether another page existed and could not draw a pager at all. It was
        // already slicing 20 rows off the query; it just never said so.
        $__meta = $this->pageMeta($totals, $request, $per);

        // Get paginated data
        $usingCursor = filled($request->input('cursor'));
        $transactions = $this->applyCursor($transactions, $request, 'transactions.trans_date', 'transactions.id');
        $results = $this->orderForCursor($transactions, 'transactions.trans_date', 'transactions.id')
            // A cursor already names where to resume; an offset on top would
            // skip a page's worth of rows a second time.
            ->skip($usingCursor ? 0 : $offset)
            ->take($per)
            ->with(['mpesa', 'cash', 'vehicle.sacco']) // eager load relationships for frontend if needed
            ->get();

        $this->markSource($results);

        // The range this actually covered, echoed back. Without it a client
        // cannot tell a server that honoured from/to from one that quietly fell
        // back to a single day — and a client that assumes the worst says so on
        // screen: NICCO's dashboard warned "only 2026-08-29 is shown" above a
        // table of 30 August rows and a two-day total. The money was right and
        // the caption was wrong, which is the shape of complaint that costs a
        // day to answer. `to` is the last INCLUDED day, so it renders directly.
        return response()->json(array_merge([
            'transactions' => $results,
            'mpesa' => $mpesaSum,
            'cash' => $cashSum,
            'wallet' => $walletSum,
            'range' => $this->rangeMeta($from_date, $to_date),
            'next_cursor' => $this->nextCursor($results->all(), 'trans_date', 'id', $per),
        ], $__meta));
    }

    /**
     * Every filter this screen supports, in one place.
     *
     * The list, the totals and the export all read through here. A filter that
     * applied to the table but not the download would produce a CSV that does
     * not match the screen it was exported from — which is the kind of thing
     * somebody reconciles against and then cannot explain.
     *
     * Tenancy is NOT applied here: Transaction carries SaccoScope and
     * BelongsToFinancier, so the query is already confined to the caller's SACCO
     * and, for a bank, to the fleet it financed. Repeating it by hand is how
     * those boundaries drift apart.
     */
    private function baseQuery(Request $request, Carbon $from_date, Carbon $to_date, bool $forTotals = false)
    {
        $vehicles = array_filter(array_map('trim', explode(',', str_replace(['[', ']'], '', $request->vehicles ?? ''))));
        $search = $request->search ?? '';
        $amount = $request->amount;

        $transactions = Transaction::select('transactions.*')
            ->leftJoin('mpesas', 'transactions.mpesa_id', '=', 'mpesas.id')
            ->leftJoin('cashes', 'transactions.cash_id', '=', 'cashes.id')
            ->join('vehicles', 'transactions.vehicle_id', '=', 'vehicles.id')
            ->leftJoin('saccos', 'vehicles.sacco_id', '=', 'saccos.id')
            // Half-open [from, to): whereBetween is inclusive at BOTH ends, so
            // a payment at exactly 00:00:00 counted into two adjacent days and
            // a range total exceeded the sum of its days.
            ->where('transactions.trans_date', '>=', $from_date)
            ->where('transactions.trans_date', '<', $to_date);

        // The one boundary the models cannot express: an investor owns buses,
        // not the SACCO, and must read only their own buses' payments. It goes
        // in baseQuery with everything else so the two total tiles and the
        // export narrow with the list — a KES 2.6M M-Pesa tile over two rows of
        // table is the shape of a support call about missing money.
        //
        // Ungated on purpose: an empty array compiles to `0 = 1`, which is how
        // an investor holding no open assignment correctly sees nothing.
        $ownedVehicleIds = $this->ownedVehicleIds();
        if ($ownedVehicleIds !== null) {
            $transactions->whereIn('transactions.vehicle_id', $ownedVehicleIds);
        }

        // Filter sacco
        if ($request->sacco > 0) {
            $transactions->where('vehicles.sacco_id', $request->sacco);
        }

        // Filter vehicles
        if (count($vehicles) > 0) {
            $transactions->whereIn('transactions.vehicle_id', $vehicles);
        }

        // Search across mpesa, cash, vehicle, sacco fields.
        //
        // TWO SHAPES, ONE SET. Which is cheaper depends entirely on whether the
        // caller can stop early, so the list and the totals ask differently.
        if ($search !== '') {
            $like = '%'.$search.'%';
            $plate = PlateSql::matchBinding($search);

            if ($forTotals) {
                // The count and the money tiles have no LIMIT to stop at, so
                // testing every row in the window against the joined tables
                // means joining ALL of them: 1,161ms on production for a
                // nine-day NICCO window, and that shape is ~44% of all database
                // time across the four aggregate queries this screen fires.
                //
                // Asking mpesas FIRST lets its trigram indexes answer instead
                // (83ms) -- but ONLY bounded. Unbounded it hashes every
                // matching payer in two years of history before it can probe,
                // measured at 41.8s for a common first name, which is why the
                // list below must NOT be rewritten this way.
                //
                // The bound is sound because transactions.trans_date is WRITTEN
                // from mpesas.TransTime: across 372,379 production rows the
                // largest gap between the two is 0.000h, and one row since
                // January sits more than a day apart. The day of slack is
                // margin, not a filter -- the outer trans_date window is still
                // what decides which rows come back, so widening here can never
                // widen the answer.
                $mpesaFrom = $from_date->copy()->subDay();
                $mpesaTo = $to_date->copy()->addDay();

                $transactions->where(function ($q) use ($like, $plate, $mpesaFrom, $mpesaTo) {
                    $q->whereIn('transactions.mpesa_id', function ($sub) use ($like, $mpesaFrom, $mpesaTo) {
                        $sub->select('id')->from('mpesas')
                            ->where('TransTime', '>=', $mpesaFrom)
                            ->where('TransTime', '<', $mpesaTo)
                            ->where(function ($w) use ($like) {
                                $w->where('TransID', LikeSql::op(), $like)
                                    ->orWhere('FirstName', LikeSql::op(), $like)
                                    ->orWhere('MiddleName', LikeSql::op(), $like)
                                    ->orWhere('LastName', LikeSql::op(), $like);
                            });
                    })->orWhereIn('transactions.cash_id', function ($sub) use ($like) {
                        $sub->select('id')->from('cashes')
                            ->where(function ($w) use ($like) {
                                $w->where('trans_id', LikeSql::op(), $like)
                                    ->orWhere('firstname', LikeSql::op(), $like)
                                    ->orWhere('lastname', LikeSql::op(), $like);
                            });
                    })->orWhereIn('transactions.vehicle_id', function ($sub) use ($like, $plate) {
                        // 946 rows: no bound needed, and none wanted -- the
                        // plate and the SACCO name are not time-bounded facts.
                        $sub->select('vehicles.id')->from('vehicles')
                            ->leftJoin('saccos', 'vehicles.sacco_id', '=', 'saccos.id')
                            ->where(function ($w) use ($like, $plate) {
                                $w->whereRaw(PlateSql::matchSql('vehicles.plate'), [$plate])
                                    ->orWhere('saccos.name', LikeSql::op(), $like);
                            });
                    });
                });
            } else {
                // The list stops at twenty. Walking trans_date DESC and testing
                // each row as it goes finds them almost immediately for any
                // common term -- 4.9ms where the totals' shape takes 250ms --
                // because the ORDER BY lets it quit. Leave it alone.
                $transactions->where(function ($q) use ($like, $plate) {
                    $q->where('mpesas.TransID', LikeSql::op(), $like)
                        ->orWhere('mpesas.FirstName', LikeSql::op(), $like)
                        ->orWhere('mpesas.MiddleName', LikeSql::op(), $like)
                        ->orWhere('mpesas.LastName', LikeSql::op(), $like)
                        ->orWhere('cashes.trans_id', LikeSql::op(), $like)
                        ->orWhere('cashes.firstname', LikeSql::op(), $like)
                        ->orWhere('cashes.lastname', LikeSql::op(), $like)
                        // Normalised on both sides, so "KDX434C" and "kdx-434c"
                        // find "KDX 434C" here exactly as they already do on the
                        // vehicles list and at driver login.
                        ->orWhereRaw(PlateSql::matchSql('vehicles.plate'), [$plate])
                        ->orWhere('saccos.name', LikeSql::op(), $like);
                });
            }
        }

        // Filter by amount (exact match)
        if ($amount !== '' && $amount !== null) {
            $transactions->where('transactions.amount', $amount);
        }

        return $transactions;
    }

    /**
     * Export a transaction listing
     *
     * CSV or PDF of the SAME rows the screen shows, for the same filters. The
     * dashboard could export summaries — one row per bus per day — but there was
     * no way to download the individual payments behind them, which is what
     * anybody reconciling a day's takings for one matatu actually needs.
     *
     * @authenticated
     *
     * @queryParam format string csv or pdf. Default csv. Example: csv
     * @queryParam date string A single day. Example: 2026-08-28
     * @queryParam from string Range start, used with to. Example: 2026-08-01
     * @queryParam to string Range end, inclusive. Example: 2026-08-28
     * @queryParam vehicles string Comma-separated vehicle ids. Example: 151
     * @queryParam sacco integer Narrow to one SACCO (super admin). Example: 4
     * @queryParam search string Receipt, payer name, plate or SACCO. Example: UHQ434J0C3
     */
    public function export(Request $request)
    {
        $format = strtolower((string) $request->input('format', 'csv'));

        if (! in_array($format, ['csv', 'pdf'], true)) {
            return response()->json(['error' => 'format must be csv or pdf'], 400);
        }

        $source = PaymentSource::normalise($request->input('source'));
        if ($source !== null && ! PaymentSource::isKnown($source)) {
            return response()->json([
                'error' => 'Unknown source. Expected one of: '.implode(', ', PaymentSource::filters()).'.',
            ], 400);
        }

        [$from, $to] = $this->dateRange($request);

        // No pagination here on purpose — an export of page one is not an
        // export. It is bounded by the date window instead, and capped so a
        // careless year-long range cannot try to stream a million rows into a
        // spreadsheet nobody can open.
        $exportQuery = $this->baseQuery($request, $from, $to);

        // The download narrows with the screen. Without this a source-filtered
        // page would export every rail, which is worse than not offering the
        // filter at all.
        $this->narrowToSource($exportQuery, $source);

        $max = $this->exportMaxRows($format);
        $count = (clone $exportQuery)->count();
        if ($count > $max) {
            return response()->json([
                'error' => sprintf(
                    'This export has %s payments; a %s download holds at most %s. Narrow the date range or filter by vehicle.',
                    number_format($count), strtoupper($format), number_format($max)
                ),
                'rows' => $count,
                'limit' => $max,
            ], 422);
        }

        $rows = $exportQuery
            ->with(['mpesa', 'cash', 'vehicle.sacco'])
            ->orderBy('transactions.trans_date', 'DESC')
            ->orderBy('transactions.id', 'DESC');

        $label = $from->toDateString().'_to_'.$to->copy()->subDay()->toDateString();

        return $format === 'pdf'
            ? $this->exportPdf($rows->get(), $from, $to, $label)
            : $this->exportCsv($rows, $label);
    }

    private function exportCsv(Builder $rows, string $label): StreamedResponse
    {
        return response()->stream(function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['Date', 'Reference', 'Payer', 'Phone', 'Plate', 'SACCO', 'Method', 'Amount']);

            $total = 0.0;
            $count = 0;
            // Tracked apart because a WALLET fare is EARNED, NOT COLLECTED: the
            // money is in the Komiut float until a settlement is paid out, so a
            // single TOTAL on the document an operator reconciles against their
            // bank statement overstates what reached the bank.
            $wallet = 0.0;
            $walletCount = 0;

            // Streamed in pages of 2,000 so a fleet-wide day (tens of thousands
            // of rows, each with its M-Pesa row and vehicle) never sits in
            // memory whole; the ORDER BY includes the id so pages do not shift.
            foreach ($rows->lazy(2000) as $t) {
                $total += (float) $t->amount;
                $count++;

                if ($t->wallet_transaction_id !== null) {
                    $wallet += (float) $t->amount;
                    $walletCount++;
                }
                fputcsv($out, [
                    TransDate::dateTime($t->trans_date),
                    $this->reference($t),
                    $this->payer($t),
                    $this->payerPhone($t),
                    $t->vehicle?->plate,
                    $t->vehicle?->sacco?->name,
                    $t->mpesa_id ? 'M-Pesa' : ($t->cash_id ? 'Cash' : ($t->wallet_transaction_id ? 'Wallet' : '')),
                    number_format((float) $t->amount, 2, '.', ''),
                ]);
            }

            fputcsv($out, []);

            // Only when there IS float money, so an export of a day without it
            // keeps the shape every existing consumer already reads.
            if ($walletCount > 0) {
                fputcsv($out, ['BANKED', '', '', '', '', '', ($count - $walletCount).' txn(s)', number_format($total - $wallet, 2, '.', '')]);
                fputcsv($out, ['IN WALLET FLOAT', '', '', '', '', '', $walletCount.' txn(s)', number_format($wallet, 2, '.', '')]);
            }

            fputcsv($out, ['TOTAL', '', '', '', '', '', $count.' txn(s)', number_format($total, 2, '.', '')]);
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="transactions_'.$label.'.csv"',
        ]);
    }

    private function exportPdf($rows, Carbon $from, Carbon $to, string $label)
    {
        // Split for the same reason the CSV footer is -- see exportCsv.
        $walletRows = $rows->filter(fn ($t) => $t->wallet_transaction_id !== null);

        $pdf = Pdf::loadView('reports.transactions', [
            'rows' => $rows,
            'from' => $from,
            'to' => $to->copy()->subDay(),
            'total' => $rows->sum(fn ($t) => (float) $t->amount),
            'wallet' => $walletRows->sum(fn ($t) => (float) $t->amount),
            'walletCount' => $walletRows->count(),
            'controller' => $this,
        ])->setPaper('a4', 'landscape');

        return $pdf->download('transactions_'.$label.'.pdf');
    }

    /**
     * Narrow the listing to one payment rail.
     *
     * Only ever ANDs another predicate onto the builder it is handed, so it can
     * never widen: it cannot reach past Transaction's SaccoScope, and it cannot
     * re-admit a row the date, vehicle or investor filters already excluded. A
     * null source adds nothing at all.
     */
    private function narrowToSource(Builder $transactions, ?string $source): void
    {
        if ($source === null) {
            return;
        }

        if ($source === PaymentSource::CASH) {
            // whereNotNull, not a truthiness test, because that is exactly what
            // the $cashSum beside the table uses. Filter and total must agree on
            // what "cash" means or the screen contradicts itself.
            $transactions->whereNotNull('transactions.cash_id');

            return;
        }

        if ($source === PaymentSource::WALLET) {
            $transactions->whereNotNull('transactions.wallet_transaction_id');

            return;
        }

        // qr and mpesa are the SAME money on the same till. The QR record is the
        // only thing separating them, so the rail predicate is shared and only
        // the EXISTS flips.
        $transactions->whereNotNull('transactions.mpesa_id');
        PaymentSource::constrainQr($transactions, 'mpesas.TransID', $source === PaymentSource::QR);
    }

    /**
     * Stamp each row of the page with the rail it arrived on.
     *
     * `mpesa` is already eager loaded, so plucking the receipts costs nothing,
     * and the QR set is resolved in ONE whereIn for the whole page — not a
     * lookup per row, which at 20 rows over a 1.3M-row table is 20 avoidable
     * round trips every time somebody clicks next.
     *
     * `source` is display-only: it is set on the model but has no column, so
     * nothing on this path may ever call save().
     */
    private function markSource(Collection $results): void
    {
        $qrReceipts = PaymentSource::qrReceipts($results->pluck('mpesa.TransID')->all());

        foreach ($results as $transaction) {
            // The pre-existing rail, unchanged. null-checks rather than
            // truthiness, so this agrees with the whereNotNull the totals and
            // the filter use. A row carrying neither stays null rather than
            // being assigned a rail it does not have — a broken link the screen
            // should show, not paper over.
            $rail = match (true) {
                $transaction->mpesa_id !== null => PaymentSource::MPESA,
                $transaction->cash_id !== null => PaymentSource::CASH,
                $transaction->wallet_transaction_id !== null => PaymentSource::WALLET,
                default => null,
            };

            $transaction->setAttribute(
                'source',
                PaymentSource::forReceipt($transaction->mpesa?->TransID, $qrReceipts, $rail)
            );
        }
    }

    /** The M-Pesa receipt, or the cash reference. Public for the PDF view. */
    public function reference($t): ?string
    {
        return $t->mpesa?->TransID ?? $t->cash?->trans_id;
    }

    /** Who paid. Public for the PDF view. */
    public function payer($t): ?string
    {
        if ($t->mpesa !== null) {
            return trim(implode(' ', array_filter([
                $t->mpesa->FirstName, $t->mpesa->MiddleName, $t->mpesa->LastName,
            ]))) ?: null;
        }

        return trim(implode(' ', array_filter([$t->cash?->firstname, $t->cash?->lastname]))) ?: null;
    }

    /** Public for the PDF view. */
    public function payerPhone($t): ?string
    {
        return $t->mpesa?->MSISDN ?? $t->cash?->phone;
    }
}
