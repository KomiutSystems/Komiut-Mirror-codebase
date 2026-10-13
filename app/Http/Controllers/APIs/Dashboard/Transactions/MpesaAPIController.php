<?php

namespace App\Http\Controllers\APIs\Dashboard\Transactions;

use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Concerns\ResolvesDateRange;
use App\Http\Controllers\Concerns\ScopesToOwnedVehicles;
use App\Http\Controllers\Concerns\SeeksByCursor;
use App\Http\Controllers\Controller;
use App\Models\Mpesa;
use App\Models\Scopes\FinancierScope;
use App\Services\Payments\PaymentSource;
use App\Services\Sql\LikeSql;
use App\Services\Sql\PlateSql;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MpesaAPIController extends Controller
{
    use PaginatesResults;
    use ResolvesDateRange;
    use ScopesToOwnedVehicles;
    use SeeksByCursor;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function getTransactions(Request $request)
    {

        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $per = $this->perPage($request, 20, self::LEDGER_MAX_PER_PAGE);
        $offset = $page * $per;
        [$from_date, $to_date] = $this->dateRange($request);
        $vehicles = explode(',', str_replace(']', '', str_replace('[', '', $request->vehicles)));
        $all_vehicles = [];

        foreach ($vehicles as $vehicle) {
            $v = trim($vehicle);
            if ($v != '') {
                array_push($all_vehicles, trim($vehicle));
            }
        }

        // Rejected rather than ignored: an unrecognised value that quietly
        // returned every till payment would put non-QR money under a QR heading.
        // `source` is new, so no existing dashboard call can hit this.
        $source = PaymentSource::normalise($request->input('source'));
        if ($source !== null && ! PaymentSource::isKnown($source)) {
            return response()->json([
                'error' => 'Unknown source. Expected one of: '.implode(', ', PaymentSource::filters()).'.',
            ], 400);
        }

        $mpesa = Mpesa::with(['transaction.vehicle.sacco'])
            // Half-open [from, to): whereBetween is inclusive at BOTH ends, so
            // a payment at exactly midnight counted into two adjacent days.
            ->where('TransTime', '>=', $from_date)
            ->where('TransTime', '<', $to_date);

        // SaccoScope and FinancierScope have already confined this query; the
        // only gap is that SaccoScope returns early on a null sacco_id. Banks
        // must be tested FIRST because a bank is saccoless by design, and would
        // otherwise fall into the deny below.
        $user = auth()->user();
        if ($user && ! $user->isSuperAdmin()) {
            if (! FinancierScope::confines($user) && $user->currentSaccoId() === null) {
                $mpesa = $mpesa->whereRaw('1 = 0');
            }
        }

        // A fourth tier, and the only one about OWNERSHIP rather than tenancy:
        // an investor reads their own buses' payments, not the SACCO's. Mpesa
        // carries no vehicle_id, so ownership is checked one hop out through the
        // transaction that matched the payment to a till — a payment nothing
        // ever matched has no owner and correctly drops out.
        //
        // Ungated: an empty array compiles to `0 = 1`, so an investor with no
        // open assignment sees nothing rather than the whole fleet's takings.
        $ownedVehicleIds = $this->ownedVehicleIds();
        if ($ownedVehicleIds !== null) {
            $mpesa = $mpesa->whereHas('transaction', function ($query) use ($ownedVehicleIds) {
                $query->whereIn('vehicle_id', $ownedVehicleIds);
            });
        }

        if ($request->sacco > 0) {
            $mpesa = $mpesa->whereHas('transaction.vehicle', function ($query) use ($request) {
                $query->where('sacco_id', $request->sacco);
            });
        }
        if (count($all_vehicles) > 0) {
            $mpesa = $mpesa->whereHas('transaction', function ($query) use ($all_vehicles) {
                $query->whereIn('vehicle_id', $all_vehicles);
            });
        }
        // Guard the WHOLE group, not one column: an empty box makes this
        // LIKE '%%' across four unindexable columns plus two correlated
        // EXISTS, which took komiut.com down for ~6 hours on 2026-08-07.
        if (filled($request->search)) {
            $this->search($mpesa, (string) $request->search, $from_date, $to_date);
        }

        if ($request->amount != '') {
            // TransAmount is a VARCHAR holding money, so a text comparison
            // never matched "100.00" and sorted "9" above "100". The grammar
            // wrap survives PostgreSQL's lower-casing of the mixed-case name;
            // NULLIF guards empty strings it refuses to cast.
            $column = DB::connection()->getQueryGrammar()->wrap('TransAmount');
            $mpesa = $mpesa->whereRaw(
                "CAST(NULLIF({$column}, '') AS DECIMAL(15,2)) = ?",
                [(float) $request->amount]
            );
        }
        // BEFORE pageMeta, so the pager counts the rows the filter leaves.
        $this->narrowToSource($mpesa, $source);

        $__meta = $this->pageMeta($mpesa, $request, $per);
        $usingCursor = filled($request->input('cursor'));
        $mpesa = $this->applyCursor($mpesa, $request, 'mpesas.TransTime', 'mpesas.id');
        $mpesa = $this->orderForCursor($mpesa, 'mpesas.TransTime', 'mpesas.id')
            ->skip($usingCursor ? 0 : $offset)->take($per)->get();

        $this->markSource($mpesa);

        return response()->json(array_merge([
            'mpesa' => $mpesa,
            'next_cursor' => $this->nextCursor($mpesa->all(), 'TransTime', 'id', $per),
        ], $__meta));
    }

    /** Up to this many of the named buses' payments, see search(). */
    private const SEARCH_UNION_MAX_PAYMENTS = 3000;

    /**
     * A payment matches when its receipt or payer name contains the term, or
     * it was paid to a bus whose plate matches, or to any bus of a SACCO whose
     * name contains it.
     *
     * This was one OR across the four text columns and two EXISTS. The EXISTS
     * kept PostgreSQL off the trigram indexes on those columns, so every search
     * walked and tested every payment in the range: a month is ~1.3M rows, and
     * a payer name took 15s to count, most of it disk reads. Now the shape
     * depends on how many payments the buses the term names took in the range,
     * found by a probe of transactions(vehicle_id, trans_date) that stops at
     * SEARCH_UNION_MAX_PAYMENTS (measured on production, a month's range):
     *
     *   none      receipt / payer name only, through the trigram indexes.
     *             A name: 15s -> 0.6s; a receipt: 0.06s.
     *   a few     that, UNION those payments. Each one is then fetched by id,
     *             which is quick for a bus's month (a plate: 4.7s -> 0.5s) and
     *             slower than walking the range for tens of thousands of them,
     *             where PostgreSQL also gives up and scans the whole table.
     *   many      a partial plate, a SACCO's name: walk the range as before,
     *             testing each payment against both sets -- the buses' set
     *             now bounded to the range, not every payment they ever took.
     */
    private function search(Builder $mpesa, string $term, Carbon $from, Carbon $to): void
    {
        $like = '%'.$term.'%';

        $buses = fn ($q) => $q->select('v.id')->from('vehicles as v')
            ->leftJoin('saccos as s', 's.id', '=', 'v.sacco_id')
            ->where(fn ($w) => $w->whereRaw(PlateSql::matchSql('v.plate'), [PlateSql::matchBinding($term)])
                ->orWhere('s.name', LikeSql::op(), $like));

        $byText = fn ($q) => $q->select('m.id')->from('mpesas as m')
            ->where('m.TransTime', '>=', $from)
            ->where('m.TransTime', '<', $to)
            ->where(fn ($w) => $w->where('m.TransID', LikeSql::op(), $like)
                ->orWhere('m.FirstName', LikeSql::op(), $like)
                ->orWhere('m.MiddleName', LikeSql::op(), $like)
                ->orWhere('m.LastName', LikeSql::op(), $like));

        // trans_date is copied from TransTime when a payment is matched to a
        // bus (2.5M of 2.5M agree to the second); the day's margin only lets
        // the index bound the walk. The exact range is the outer query's.
        $byBus = fn ($q) => $q->select('t.mpesa_id')->from('transactions as t')
            ->whereNotNull('t.mpesa_id')
            ->where('t.trans_date', '>=', $from->copy()->subDay())
            ->where('t.trans_date', '<', $to->copy()->addDay())
            ->whereIn('t.vehicle_id', $buses);

        $busPayments = DB::query()->fromSub(
            $byBus(DB::query())->limit(self::SEARCH_UNION_MAX_PAYMENTS + 1),
            'bus_payments'
        )->count();

        if ($busPayments === 0) {
            $mpesa->whereIn('mpesas.id', $byText);
        } elseif ($busPayments <= self::SEARCH_UNION_MAX_PAYMENTS) {
            $mpesa->whereIn('mpesas.id', fn ($q) => $byText($q)->union($byBus(DB::query())));
        } else {
            $mpesa->where(fn ($w) => $w->whereIn('mpesas.id', $byText)->orWhereIn('mpesas.id', $byBus));
        }
    }

    /**
     * Narrow this listing to one payment rail.
     *
     * Every branch only ANDs a predicate onto the builder, so the filter can
     * never widen — it cannot undo the SACCO confinement above, and it cannot
     * re-admit a row the date, vehicle or investor filters already dropped.
     */
    private function narrowToSource(Builder $mpesa, ?string $source): void
    {
        if ($source === null) {
            return;
        }

        if ($source === PaymentSource::CASH) {
            // Every row here came off a till; none of it is cash. Narrow to
            // nothing rather than ignore the filter — answering ?source=cash
            // with a full page of M-Pesa money is how an operator books till
            // receipts as cash and the day stops balancing.
            $mpesa->whereRaw('1 = 0');

            return;
        }

        // No whereNotNull rail guard is needed the way /transactions needs one:
        // every row on this listing already has a TransID of its own, so the
        // EXISTS is the only thing separating scanned money from till money.
        PaymentSource::constrainQr($mpesa, 'mpesas.TransID', $source === PaymentSource::QR);
    }

    /**
     * Stamp each row of the page with the rail it arrived on.
     *
     * ONE whereIn resolves the whole page's QR receipts. A per-row lookup would
     * add 20 queries to every page of a 1.3M-row table, and this is the screen a
     * SACCO office leaves open all day.
     *
     * `source` is display-only — no such column exists, so these instances must
     * never be saved.
     */
    private function markSource(Collection $rows): void
    {
        $qrReceipts = PaymentSource::qrReceipts($rows->pluck('TransID')->all());

        foreach ($rows as $row) {
            // Fallback is the existing rail: everything here is M-Pesa till
            // money unless a QR record claims it.
            $row->setAttribute('source', PaymentSource::forReceipt($row->TransID, $qrReceipts, PaymentSource::MPESA));
        }
    }
}
