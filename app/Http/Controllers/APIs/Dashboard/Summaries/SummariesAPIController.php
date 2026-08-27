<?php

namespace App\Http\Controllers\APIs\Dashboard\Summaries;

use App\Http\Controllers\Concerns\ResolvesDateRange;
use App\Http\Controllers\Concerns\ScopesToOwnedVehicles;
use App\Http\Controllers\Controller;
use App\Models\Scopes\FinancierScope;
use App\Models\Summary;
use App\Services\Sql\LikeSql;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Per-vehicle takings for the caller's SACCO.
 *
 * Scoping is the Summary model's job: it declares `$saccoVia = 'vehicle'`, so
 * SaccoScope constrains every query through the vehicle's SACCO. This
 * controller must never be the thing that decides who sees what — but it MUST
 * carry the permission gate, because SaccoScope deliberately does not apply to
 * users with no home SACCO (passengers and drivers, who legitimately query
 * across SACCOs to book a ride). Without `permission:View Summaries` on the
 * route, any authenticated passenger reached an unscoped read of every SACCO's
 * revenue in the brand.
 *
 * The one boundary the model cannot express is the bank one, and it is applied
 * in baseQuery below. A financing bank is not a SACCO — NICCO MOVERS' 180
 * vehicles are 126 NCBA and 54 Co-op — so every number on this screen has to be
 * recomputed over one bank's fleet, not filtered after the fact. baseQuery is
 * why that is one line: the list, the totals footer, the CSV and the PDF all
 * read through it, and a filter that reached the page but not the footer would
 * hand a bank a total belonging to somebody else.
 */
class SummariesAPIController extends Controller
{
    use ResolvesDateRange, ScopesToOwnedVehicles;

    /** Hard ceiling on an export, so one click cannot try to render a year at once. */
    private const EXPORT_MAX_ROWS = 20000;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function getSummaries(Request $request)
    {
        [$from, $to] = $this->dateRange($request);

        $query = $this->baseQuery($request, $from, $to);

        // COUNT over a grouped query counts groups, not rows — paginate() gets
        // this wrong and reports the row count. Count the distinct vehicles.
        $total = (clone $query)->distinct('summaries.vehicle_id')->count('summaries.vehicle_id');

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
        $currentPage = max((int) $request->input('page', 1), 1);
        $lastPage = (int) max(ceil($total / $perPage), 1);

        $summaries = (clone $query)
            ->select(
                'summaries.vehicle_id',
                DB::raw('SUM(mpesa_amount) as mpesa_amount'),
                DB::raw('SUM(mpesa_txn) as mpesa_txn'),
                DB::raw('SUM(cash_amount) as cash_amount'),
                DB::raw('SUM(cash_txn) as cash_txn'),
                DB::raw('SUM(mpesa_amount + cash_amount) as totals'),
                DB::raw('SUM(mpesa_txn + cash_txn) as total_txn'),
                DB::raw($this->expenseSum().' as expense_fee_amount'),
                DB::raw('SUM(mpesa_amount + cash_amount) - COALESCE('.$this->expenseSum().', 0) as net_amount'),
            )
            ->groupBy('summaries.vehicle_id')
            ->orderByRaw('SUM(mpesa_amount + cash_amount) DESC')
            ->skip(($currentPage - 1) * $perPage)->take($perPage)
            ->with(['vehicle.sacco', 'vehicle.seat'])
            ->get();

        $totals = $this->totals($request, $from, $to);

        return response()->json([
            'summaries' => $summaries,
            // Kept for existing callers, which read these two directly.
            'mpesa' => $totals['mpesa_amount'],
            'cash' => $totals['cash_amount'],
            // Whole filtered set, not just this page — a footer must not change
            // as you page through.
            'totals' => $totals,
            'range' => ['from' => $from->toDateString(), 'to' => $to->copy()->subDay()->toDateString()],
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $currentPage,
            'last_page' => $lastPage,
        ]);
    }

    /** CSV or PDF of the same rows the list shows, for the same filters. */
    public function export(Request $request)
    {
        $format = strtolower((string) $request->input('format', 'csv'));
        if (! in_array($format, ['csv', 'pdf'], true)) {
            return response()->json(['error' => 'format must be csv or pdf'], 400);
        }

        [$from, $to] = $this->dateRange($request);
        $rows = $this->exportRows($request, $from, $to);
        $totals = $this->totals($request, $from, $to);
        $label = $this->dateRangeLabel($from, $to);

        return $format === 'pdf'
            ? $this->pdf($rows, $totals, $from, $to, $label)
            : $this->csv($rows, $totals, $label);
    }

    /**
     * Shared filters. Everything the list, the totals and the export read comes
     * through here, so a filter can never apply to the page but not the footer.
     */
    private function baseQuery(Request $request, Carbon $from, Carbon $to)
    {
        $query = Summary::query()
            ->join('vehicles', 'summaries.vehicle_id', '=', 'vehicles.id')
            ->leftJoin('saccos', 'vehicles.sacco_id', '=', 'saccos.id')
            // Half-open: trans_date is a date/timestamp, and an inclusive
            // between() would count the next day's 00:00:00 rows into both days.
            ->where('trans_date', '>=', $from)
            ->where('trans_date', '<', $to);

        // The bank boundary is applied by Summary's own BelongsToFinancier
        // scope, not here — everything this controller returns (page, footer,
        // CSV, PDF) is built from this one query, so the model-level scope
        // covers all four at once. That matters most for the UNGROUPED sums in
        // totals(): for a caller looking at NICCO an unscoped total is a
        // mixed-bank number meaningless to either bank.

        // The OWNERSHIP boundary, which no model scope expresses: an investor
        // reads the takings of their own buses, not the SACCO's. It sits here
        // for the same reason the bank comment above gives — the list, the
        // totals footer, the CSV and the PDF are all built from this query, and
        // totals() is the sharpest case, being an ungrouped SUM. A filter that
        // reached the table but not the footer would show an investor two buses
        // under a KES 2.6M headline, which reads as missing money.
        //
        // Ungated on purpose: an empty array compiles to `0 = 1`, so an investor
        // with no open assignment gets nothing. `if (count($ids) > 0)` here
        // would show them all ~147 reporting vehicles instead.
        $ownedVehicleIds = $this->ownedVehicleIds();
        if ($ownedVehicleIds !== null) {
            $query->whereIn('summaries.vehicle_id', $ownedVehicleIds);
        }

        if ($request->sacco > 0) {
            $query->where('vehicles.sacco_id', $request->sacco);
        }

        $vehicles = array_filter(array_map('trim', explode(',', str_replace(['[', ']'], '', (string) $request->vehicles))));
        if ($vehicles !== []) {
            $query->whereIn('summaries.vehicle_id', $vehicles);
        }

        if (filled($request->search)) {
            $term = '%'.$request->search.'%';
            $query->where(function ($q) use ($term) {
                $q->where('vehicles.plate', LikeSql::op(), $term)
                    ->orWhere('saccos.name', LikeSql::op(), $term);
            });
        }

        return $query;
    }

    /**
     * expense_fee_amount was added later as a STRING with default '0', while the
     * money columns are doubles. PostgreSQL has no sum(character varying) and
     * 500s the request; NULLIF also covers rows holding '' rather than '0'.
     */
    private function expenseSum(): string
    {
        return "SUM(CAST(NULLIF(expense_fee_amount, '') AS DECIMAL(15,2)))";
    }

    /** Totals across the WHOLE filtered set, independent of pagination. */
    private function totals(Request $request, Carbon $from, Carbon $to): array
    {
        $r = (clone $this->baseQuery($request, $from, $to))->select(
            DB::raw('COALESCE(SUM(mpesa_amount), 0) as mpesa_amount'),
            DB::raw('COALESCE(SUM(cash_amount), 0) as cash_amount'),
            DB::raw('COALESCE(SUM(mpesa_txn), 0) as mpesa_txn'),
            DB::raw('COALESCE(SUM(cash_txn), 0) as cash_txn'),
            DB::raw('COALESCE('.$this->expenseSum().', 0) as expense_fee_amount'),
            DB::raw('COUNT(DISTINCT summaries.vehicle_id) as vehicles'),
        )->first();

        $collections = (float) $r->mpesa_amount + (float) $r->cash_amount;

        return [
            'mpesa_amount' => (float) $r->mpesa_amount,
            'cash_amount' => (float) $r->cash_amount,
            'collections' => $collections,
            'mpesa_txn' => (int) $r->mpesa_txn,
            'cash_txn' => (int) $r->cash_txn,
            'total_txn' => (int) $r->mpesa_txn + (int) $r->cash_txn,
            'expense_fee_amount' => (float) $r->expense_fee_amount,
            // What the SACCO actually keeps — the number the collections
            // headline does not answer on its own.
            'net_amount' => $collections - (float) $r->expense_fee_amount,
            'vehicles' => (int) $r->vehicles,
        ];
    }

    private function exportRows(Request $request, Carbon $from, Carbon $to)
    {
        return $this->baseQuery($request, $from, $to)
            ->select(
                'summaries.vehicle_id',
                'vehicles.plate',
                'saccos.name as sacco_name',
                DB::raw('SUM(mpesa_amount) as mpesa_amount'),
                DB::raw('SUM(mpesa_txn) as mpesa_txn'),
                DB::raw('SUM(cash_amount) as cash_amount'),
                DB::raw('SUM(cash_txn) as cash_txn'),
                DB::raw('SUM(mpesa_amount + cash_amount) as totals'),
                DB::raw($this->expenseSum().' as expense_fee_amount'),
            )
            ->groupBy('summaries.vehicle_id', 'vehicles.plate', 'saccos.name')
            ->orderByRaw('SUM(mpesa_amount + cash_amount) DESC')
            ->limit(self::EXPORT_MAX_ROWS)
            ->get();
    }

    private function csv($rows, array $totals, string $label): StreamedResponse
    {
        return response()->stream(function () use ($rows, $totals): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['Plate', 'SACCO', 'M-Pesa', 'M-Pesa Txns', 'Cash', 'Cash Txns', 'Collections', 'Expenses', 'Net']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r->plate, $r->sacco_name,
                    number_format((float) $r->mpesa_amount, 2, '.', ''), (int) $r->mpesa_txn,
                    number_format((float) $r->cash_amount, 2, '.', ''), (int) $r->cash_txn,
                    number_format((float) $r->totals, 2, '.', ''),
                    number_format((float) $r->expense_fee_amount, 2, '.', ''),
                    number_format((float) $r->totals - (float) $r->expense_fee_amount, 2, '.', ''),
                ]);
            }
            fputcsv($out, []);
            fputcsv($out, ['TOTAL', $totals['vehicles'].' vehicle(s)',
                number_format($totals['mpesa_amount'], 2, '.', ''), $totals['mpesa_txn'],
                number_format($totals['cash_amount'], 2, '.', ''), $totals['cash_txn'],
                number_format($totals['collections'], 2, '.', ''),
                number_format($totals['expense_fee_amount'], 2, '.', ''),
                number_format($totals['net_amount'], 2, '.', ''),
            ]);
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="summaries_'.$label.'.csv"',
        ]);
    }

    private function pdf($rows, array $totals, Carbon $from, Carbon $to, string $label)
    {
        $pdf = Pdf::loadView('reports.summaries', [
            'rows' => $rows,
            'totals' => $totals,
            'from' => $from->toDateString(),
            'to' => $to->copy()->subDay()->toDateString(),
            'sacco' => $this->coverageLabel(),
            'generatedAt' => now()->format('Y-m-d H:i'),
            'generatedBy' => trim(auth()->user()->firstname.' '.auth()->user()->lastname),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('summaries_'.$label.'.pdf');
    }

    /**
     * What the PDF header says the report covers.
     *
     * The old expression printed 'All SACCOs' for ANY user with no sacco_id.
     * For a bank user that is a flat lie in a document they will file: the rows
     * beneath it are only the vehicles their own bank financed, so a Co-op
     * statement announcing itself as all 48 SACCOs reads as the platform total.
     * Name the fleet the numbers actually describe.
     */
    private function coverageLabel(): string
    {
        $user = auth()->user();

        if (FinancierScope::confines($user)) {
            // Includes the fail-closed case, where the report is empty because
            // the financier would not resolve. 'All SACCOs' over an empty table
            // would read as "the platform took nothing today".
            return ($user->currentFinancier()?->label() ?? 'Unrecognised bank').' financed fleet';
        }

        // Same lie, one tier down. An investor's rows are their own two or three
        // buses, so heading the PDF 'NICCO MOVERS LIMITED' states that this is
        // the SACCO's day — off by ~147 vehicles in a document somebody files.
        // ownedVehicleIds() is non-null exactly when the caller was narrowed,
        // and is already memoised by the query this report was built from.
        if ($this->ownedVehicleIds() !== null) {
            $sacco = optional($user->sacco)->name;

            return $sacco === null ? 'Own vehicles' : $sacco.' — own vehicles';
        }

        return optional($user->sacco)->name ?? 'All SACCOs';
    }
}
