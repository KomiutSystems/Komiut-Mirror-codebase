<?php

namespace App\Http\Controllers\APIs\Dashboard\Transactions;

use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\Sql\LikeSql;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransactionsAPIController extends Controller
{
    use PaginatesResults;


    public function __construct(){
        $this->middleware('auth:sanctum');
    }

    public function getTransactions(Request $request)
    {
        $page = max(intval($request->page ?? 1), 1) - 1;
        $offset = $page * 20;

        [$from_date, $to_date] = $this->range($request);
        $transactions = $this->baseQuery($request, $from_date, $to_date);

        // The two totals beside the table are SUMs over the WHOLE filtered set,
        // so unlike the twenty rows below them they have no LIMIT to stop at and
        // cost far more than the page does. Cached on the same key as the count,
        // tagged apart so the mpesa and cash figures cannot collide.
        $mpesaSum = $this->cachedScalar(
            $transactions,
            'txn:mpesa',
            fn () => (clone $transactions)->whereNotNull('transactions.mpesa_id')->sum('transactions.amount')
        );
        $cashSum = $this->cachedScalar(
            $transactions,
            'txn:cash',
            fn () => (clone $transactions)->whereNotNull('transactions.cash_id')->sum('transactions.amount')
        );

        // Page metadata. This endpoint returned rows, an mpesa total and a cash
        // total, and nothing about paging — so the client had no way to know
        // whether another page existed and could not draw a pager at all. It was
        // already slicing 20 rows off the query; it just never said so.
        $__meta = $this->pageMeta($transactions, $request, 20);

        // Get paginated data
        $results = $transactions->orderBy('transactions.trans_date', 'DESC')
            ->skip($offset)
            ->take(20)
            ->with(['mpesa', 'cash', 'vehicle.sacco']) // eager load relationships for frontend if needed
            ->get();

        return response()->json(array_merge([
            'transactions' => $results,
            'mpesa' => $mpesaSum,
            'cash' => $cashSum,
        ], $__meta));
    }

    /**
     * The date window this screen is looking at.
     *
     * `date` is a single day and is what the dashboard sends today. from/to add
     * a range the screen could not previously express — same shape the summaries
     * export already accepts, so the two exports take the same parameters.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(Request $request): array
    {
        if (filled($request->from) || filled($request->to)) {
            $from = Carbon::parse($request->input('from', $request->input('to')))->startOfDay();
            $to = Carbon::parse($request->input('to', $request->input('from')))->startOfDay()->addDay();
        } else {
            $from = filled($request->date) ? Carbon::parse($request->date)->startOfDay() : Carbon::today();
            $to = $from->copy()->addDay();
        }

        return [$from, $to->lessThanOrEqualTo($from) ? $from->copy()->addDay() : $to];
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
    private function baseQuery(Request $request, Carbon $from_date, Carbon $to_date)
    {
        $vehicles = array_filter(array_map('trim', explode(',', str_replace(['[', ']'], '', $request->vehicles ?? ''))));
        $search = $request->search ?? '';
        $amount = $request->amount;

        $transactions = Transaction::select('transactions.*')
            ->leftJoin('mpesas', 'transactions.mpesa_id', '=', 'mpesas.id')
            ->leftJoin('cashes', 'transactions.cash_id', '=', 'cashes.id')
            ->join('vehicles', 'transactions.vehicle_id', '=', 'vehicles.id')
            ->leftJoin('saccos', 'vehicles.sacco_id', '=', 'saccos.id')
            ->whereBetween('transactions.trans_date', [$from_date, $to_date]);

        // Filter sacco
        if ($request->sacco > 0) {
            $transactions->where('vehicles.sacco_id', $request->sacco);
        }

        // Filter vehicles
        if (count($vehicles) > 0) {
            $transactions->whereIn('transactions.vehicle_id', $vehicles);
        }

        // Search across mpesa, cash, vehicle, sacco fields
        if ($search !== '') {
            $like = '%' . $search . '%';
            $transactions->where(function ($q) use ($like) {
                $q->where('mpesas.TransID', LikeSql::op(), $like)
                    ->orWhere('mpesas.FirstName', LikeSql::op(), $like)
                    ->orWhere('mpesas.MiddleName', LikeSql::op(), $like)
                    ->orWhere('mpesas.LastName', LikeSql::op(), $like)
                    ->orWhere('cashes.trans_id', LikeSql::op(), $like)
                    ->orWhere('cashes.firstname', LikeSql::op(), $like)
                    ->orWhere('cashes.lastname', LikeSql::op(), $like)
                    ->orWhere('vehicles.plate', LikeSql::op(), $like)
                    ->orWhere('saccos.name', LikeSql::op(), $like);
            });
        }

        // Filter by amount (exact match)
        if ($amount !== "" && $amount !== null) {
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

        [$from, $to] = $this->range($request);

        // No pagination here on purpose — an export of page one is not an
        // export. It is bounded by the date window instead, and capped so a
        // careless year-long range cannot try to stream a million rows into a
        // spreadsheet nobody can open.
        $rows = $this->baseQuery($request, $from, $to)
            ->with(['mpesa', 'cash', 'vehicle.sacco'])
            ->orderBy('transactions.trans_date', 'DESC')
            ->limit(self::EXPORT_MAX_ROWS)
            ->get();

        $label = $from->toDateString().'_to_'.$to->copy()->subDay()->toDateString();

        return $format === 'pdf'
            ? $this->exportPdf($rows, $from, $to, $label)
            : $this->exportCsv($rows, $label);
    }

    private function exportCsv($rows, string $label): StreamedResponse
    {
        return response()->stream(function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['Date', 'Reference', 'Payer', 'Phone', 'Plate', 'SACCO', 'Method', 'Amount']);

            $total = 0.0;

            foreach ($rows as $t) {
                $total += (float) $t->amount;
                fputcsv($out, [
                    optional($t->trans_date)->toDateTimeString(),
                    $this->reference($t),
                    $this->payer($t),
                    $this->payerPhone($t),
                    $t->vehicle?->plate,
                    $t->vehicle?->sacco?->name,
                    $t->mpesa_id ? 'M-Pesa' : ($t->cash_id ? 'Cash' : ''),
                    number_format((float) $t->amount, 2, '.', ''),
                ]);
            }

            fputcsv($out, []);
            fputcsv($out, ['TOTAL', '', '', '', '', '', $rows->count().' txn(s)', number_format($total, 2, '.', '')]);
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="transactions_'.$label.'.csv"',
        ]);
    }

    private function exportPdf($rows, Carbon $from, Carbon $to, string $label)
    {
        $pdf = Pdf::loadView('reports.transactions', [
            'rows' => $rows,
            'from' => $from,
            'to' => $to->copy()->subDay(),
            'total' => $rows->sum(fn ($t) => (float) $t->amount),
            'controller' => $this,
        ])->setPaper('a4', 'landscape');

        return $pdf->download('transactions_'.$label.'.pdf');
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
