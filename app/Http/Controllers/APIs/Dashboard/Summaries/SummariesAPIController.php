<?php

namespace App\Http\Controllers\APIs\Dashboard\Summaries;

use App\Http\Controllers\Concerns\ResolvesDateRange;
use App\Http\Controllers\Concerns\ScopesToOwnedVehicles;
use App\Http\Controllers\Controller;
use App\Models\Scopes\FinancierScope;
use App\Models\Summary;
use App\Models\Transaction;
use App\Models\Vehicle;
use App\Services\Sql\LikeSql;
use App\Services\Sql\PlateSql;
use App\Support\TransDate;
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
                // Share of takings that came through a till rather than a
                // conductor's pocket. NULLIF so a bus that collected nothing
                // reads null rather than dividing by zero and reporting 0%,
                // which would rank an idle bus alongside a leaking one.
                DB::raw('CASE WHEN SUM(mpesa_amount + cash_amount) > 0
                    THEN ROUND((SUM(mpesa_amount) / NULLIF(SUM(mpesa_amount + cash_amount), 0) * 100)::numeric, 1)
                    ELSE NULL END as cashless_percent'),
            )
            ->groupBy('summaries.vehicle_id')
            ->orderByRaw('SUM(mpesa_amount + cash_amount) DESC')
            ->skip(($currentPage - 1) * $perPage)->take($perPage)
            ->with(['vehicle.sacco', 'vehicle.seat'])
            ->get();

        $totals = $this->totals($request, $from, $to);

        return response()->json([
            'summaries' => $summaries,
            // The buses that are NOT in the list above, because they earned
            // nothing at all. See idleVehicles().
            'idle' => $this->idleVehicles($request, $from, $to),
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
     * Collections at a glance
     *
     * For the app's management screens (SACCO admin, operations manager, fleet
     * manager, investor): the day, the week and the month containing `date` in
     * one call, the month day by day, and the buses ranked for one of those
     * periods. Built from the same baseQuery as the list, so it is scoped the
     * same way: a SACCO sees its buses, a bank the fleet it financed, an
     * investor only the buses they own.
     *
     * Weeks run Monday to Sunday; months are calendar months. Dates are Nairobi
     * days, which is what trans_date holds.
     *
     * @queryParam date string The day the periods are built around (Y-m-d). Defaults to today in Nairobi. Example: 2026-10-15
     * @queryParam period string Which period `vehicles` ranks: day, week or month. Default day. Example: week
     * @queryParam vehicles_limit integer How many buses to list, 1-200. Default 50. Example: 50
     * @queryParam sacco integer Super admin only: one SACCO. Example: 4
     */
    public function overview(Request $request)
    {
        try {
            $date = filled($request->input('date'))
                ? Carbon::createFromFormat('Y-m-d', (string) $request->input('date'))->startOfDay()
                : Carbon::now('Africa/Nairobi')->startOfDay()->shiftTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return response()->json(['error' => 'date must be Y-m-d'], 400);
        }

        $period = (string) $request->input('period', 'day');
        if (! in_array($period, ['day', 'week', 'month'], true)) {
            return response()->json(['error' => 'period must be day, week or month'], 400);
        }
        $limit = min(max((int) $request->input('vehicles_limit', 50), 1), 200);

        $ranges = [
            'day' => [$date->copy(), $date->copy()->addDay()],
            'week' => [$date->copy()->startOfWeek(Carbon::MONDAY), $date->copy()->startOfWeek(Carbon::MONDAY)->addWeek()],
            'month' => [$date->copy()->startOfMonth(), $date->copy()->startOfMonth()->addMonth()],
        ];

        $totals = [];
        foreach ($ranges as $key => [$from, $to]) {
            $totals[$key] = ['from' => $from->toDateString(), 'to' => $to->copy()->subDay()->toDateString()]
                + $this->totals($request, $from, $to);
        }

        [$monthFrom, $monthTo] = $ranges['month'];
        $byDay = (clone $this->baseQuery($request, $monthFrom, $monthTo))
            ->selectRaw('CAST(trans_date AS DATE) AS day, COALESCE(SUM(mpesa_amount), 0) AS mpesa_amount, COALESCE(SUM(cash_amount), 0) AS cash_amount, COUNT(DISTINCT summaries.vehicle_id) AS vehicles')
            ->groupByRaw('CAST(trans_date AS DATE)')
            ->get()
            ->keyBy(fn ($r) => Carbon::parse($r->day)->toDateString());
        $daily = [];
        for ($d = $monthFrom->copy(); $d->lt($monthTo); $d->addDay()) {
            $r = $byDay->get($d->toDateString());
            $daily[] = [
                'date' => $d->toDateString(),
                'mpesa_amount' => (float) ($r->mpesa_amount ?? 0),
                'cash_amount' => (float) ($r->cash_amount ?? 0),
                'collections' => (float) ($r->mpesa_amount ?? 0) + (float) ($r->cash_amount ?? 0),
                'vehicles' => (int) ($r->vehicles ?? 0),
            ];
        }

        [$from, $to] = $ranges[$period];
        $vehicles = (clone $this->baseQuery($request, $from, $to))
            ->select(
                'summaries.vehicle_id',
                'vehicles.plate',
                DB::raw('SUM(mpesa_amount) as mpesa_amount'),
                DB::raw('SUM(cash_amount) as cash_amount'),
                DB::raw('SUM(mpesa_amount + cash_amount) as collections'),
                DB::raw('SUM(mpesa_txn + cash_txn) as total_txn'),
            )
            ->groupBy('summaries.vehicle_id', 'vehicles.plate')
            ->orderByRaw('SUM(mpesa_amount + cash_amount) DESC')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'vehicle_id' => (int) $r->vehicle_id,
                'plate' => $r->plate,
                'mpesa_amount' => (float) $r->mpesa_amount,
                'cash_amount' => (float) $r->cash_amount,
                'collections' => (float) $r->collections,
                'total_txn' => (int) $r->total_txn,
            ]);

        return response()->json([
            'date' => $date->toDateString(),
            'totals' => $totals,
            'daily' => $daily,
            'period' => $period,
            'vehicles' => $vehicles,
            // Buses that earned nothing in the ranked period: the ones to chase.
            'idle' => $this->idleVehicles($request, $from, $to)['count'],
        ]);
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
            $plate = PlateSql::matchBinding((string) $request->search);
            $query->where(function ($q) use ($term, $plate) {
                $q->whereRaw(PlateSql::matchSql('vehicles.plate'), [$plate])
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

    /**
     * The buses the caller may see, as a Vehicle query; null when that is none.
     *
     * Vehicle carries SaccoScope, BrandScope and FinancierScope, so a SACCO
     * user sees their SACCO's buses and a bank the fleet it financed. Ownership
     * is the boundary no model scope expresses, applied from ownedVehicleIds()
     * as the list does. And it FAILS CLOSED for staff with no SACCO: Vehicle
     * lets a caller with no SACCO browse across SACCOs (passengers finding a
     * bus), so without this they would get every bus in the brand.
     */
    private function fleet(Request $request): ?\Illuminate\Database\Eloquent\Builder
    {
        $user = $request->user();
        $owned = $this->ownedVehicleIds();
        if ($user !== null && ! $user->isSuperAdmin() && $user->currentSaccoId() === null
            && ! $user->isBankUser() && $owned === null) {
            return null;
        }

        return Vehicle::query()
            ->when($request->sacco > 0, fn ($q) => $q->where('sacco_id', (int) $request->sacco))
            ->when($owned !== null, fn ($q) => $q->whereIn('id', $owned));
    }

    /**
     * Today so far
     *
     * Every bus the caller may see, with what it has collected TODAY up to
     * this moment -- the stage manager's screen (the Queue Supervisor role).
     * Summaries are incremented on every payment, so the amounts are live.
     * Buses that took money most recently come first; buses with nothing yet
     * today follow, by plate.
     *
     * "Today" is the Nairobi calendar day, as summaries are kept.
     *
     * @queryParam search string Part of a plate. Example: KDA
     */
    public function today(Request $request)
    {
        $now = Carbon::now('Africa/Nairobi');
        $from = $now->copy()->startOfDay()->shiftTimezone(config('app.timezone'));
        $to = $from->copy()->addDay();

        $fleet = $this->fleet($request);
        $buses = $fleet === null ? collect() : $fleet
            ->when(filled($request->search), fn ($q) => $q->whereRaw(PlateSql::matchSql('plate'), [PlateSql::matchBinding((string) $request->search)]))
            ->get(['id', 'plate']);

        $amounts = $buses->isEmpty() ? collect() : (clone $this->baseQuery($request, $from, $to))
            ->whereIn('summaries.vehicle_id', $buses->pluck('id'))
            ->select(
                'summaries.vehicle_id',
                DB::raw('SUM(mpesa_amount) as mpesa_amount'),
                DB::raw('SUM(cash_amount) as cash_amount'),
                DB::raw('SUM(mpesa_txn + cash_txn) as total_txn'),
            )
            ->groupBy('summaries.vehicle_id')
            ->get()
            ->keyBy('vehicle_id');

        // When each bus last took money today. Transactions carry the payment's
        // own time (TransTime, Nairobi wall clock); one grouped read over the
        // (vehicle_id, trans_date) index for the whole fleet.
        $last = $buses->isEmpty() ? collect() : Transaction::withoutGlobalScopes()
            ->whereIn('vehicle_id', $buses->pluck('id'))
            ->where('trans_date', '>=', $now->copy()->startOfDay()->format('Y-m-d H:i:s'))
            ->groupBy('vehicle_id')
            ->selectRaw('vehicle_id, MAX(trans_date) AS last_at')
            ->pluck('last_at', 'vehicle_id');

        $rows = $buses->map(function (Vehicle $v) use ($amounts, $last) {
            $a = $amounts->get($v->id);
            $mpesa = (float) ($a->mpesa_amount ?? 0);
            $cash = (float) ($a->cash_amount ?? 0);
            $at = $last->get($v->id);

            return [
                'vehicle_id' => (int) $v->id,
                'plate' => $v->plate,
                'collections' => $mpesa + $cash,
                'mpesa_amount' => $mpesa,
                'cash_amount' => $cash,
                'total_txn' => (int) ($a->total_txn ?? 0),
                'last_payment_at' => $at === null ? null : Carbon::parse((string) $at, 'Africa/Nairobi')->toIso8601String(),
            ];
        })
            ->sort(fn ($x, $y) => [$y['last_payment_at'] ?? '', $y['collections'], $x['plate']] <=> [$x['last_payment_at'] ?? '', $x['collections'], $y['plate']])
            ->values();

        return response()->json([
            'as_of' => $now->toIso8601String(),
            'date' => $now->toDateString(),
            'totals' => [
                'collections' => $rows->sum('collections'),
                'mpesa_amount' => $rows->sum('mpesa_amount'),
                'cash_amount' => $rows->sum('cash_amount'),
                'total_txn' => $rows->sum('total_txn'),
                'vehicles' => $rows->count(),
                'collecting' => $rows->where('collections', '>', 0)->count(),
            ],
            'vehicles' => $rows,
        ]);
    }

    /**
     * The buses that earned NOTHING in this window.
     *
     * THE PAGE COULD NOT SHOW THESE, and they are the ones worth looking at.
     * Every figure here is built from Summary::query(), so a vehicle with no
     * summary row in the range is not a zero in the table -- it is absent
     * entirely. On a 180-bus SACCO, "which twelve did not work today" was
     * structurally unanswerable, and the row you most need to see was the one
     * that was never rendered. This asks the opposite question, starting from
     * VEHICLE.
     *
     * It reads differently to each audience, which is why it earns its own
     * query rather than a zero row buried on page nine:
     *   - a SACCO sees a bus that is broken, off the road, or whose till is
     *     misconfigured and collecting into nowhere;
     *   - an owner sees their asset earning nothing;
     *   - a bank sees the clearest leading indicator it has that a financed
     *     vehicle is heading for trouble.
     *
     * SCOPING IS INHERITED, NOT REBUILT. Vehicle carries SaccoScope, BrandScope
     * and FinancierScope, so a bank sees only the fleet it financed here exactly
     * as it does in the table. Ownership is the one boundary no model scope
     * expresses, so it is applied by hand from the same ownedVehicleIds() the
     * main query uses: an investor must not learn the SACCO's idle count.
     *
     * `last_collected_at` deliberately looks BEYOND the window. "Earned nothing
     * this week" and "has earned nothing since 11 August" are different facts,
     * and the second is the one that gets a bus looked at.
     */
    private function idleVehicles(Request $request, Carbon $from, Carbon $to): array
    {
        $fleet = $this->fleet($request);
        if ($fleet === null) {
            return ['count' => 0, 'vehicles' => []];
        }

        $earned = Summary::query()
            ->where('trans_date', '>=', $from)
            ->where('trans_date', '<', $to)
            ->groupBy('vehicle_id')
            ->havingRaw('SUM(mpesa_amount + cash_amount) > 0')
            ->pluck('vehicle_id');

        $idle = $fleet->with('sacco:id,name')->whereNotIn('id', $earned)
            ->orderBy('plate')->get(['id', 'plate', 'sacco_id']);

        // One query for every "when did this bus last collect", not one per
        // vehicle: a SACCO whose fleet is parked on a Sunday would otherwise
        // pay 180 round trips to render a single panel.
        $lastSeen = Summary::query()
            ->whereIn('vehicle_id', $idle->pluck('id'))
            ->groupBy('vehicle_id')
            ->selectRaw('vehicle_id, MAX(trans_date) as last_at')
            ->pluck('last_at', 'vehicle_id');

        return [
            'count' => $idle->count(),
            'vehicles' => $idle->map(fn (Vehicle $v) => [
                'vehicle_id' => (int) $v->id,
                'plate' => $v->plate,
                'sacco' => $v->sacco?->name,
                'last_collected_at' => TransDate::dateTime($lastSeen[$v->id] ?? null),
            ])->values(),
        ];
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
            // THE FRAUD SIGNAL, and the reason this belongs on the footer as
            // well as each row. A conductor keeping fares shows up as a
            // cashless share that drifts down while trips hold steady, which is
            // only visible against the fleet's own baseline.
            'cashless_percent' => $collections > 0
                ? round((float) $r->mpesa_amount / $collections * 100, 1)
                : null,
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
