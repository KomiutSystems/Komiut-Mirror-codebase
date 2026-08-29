<?php

namespace App\Http\Controllers\APIs\Dashboard\Transactions;

use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Concerns\ResolvesDateRange;
use App\Http\Controllers\Concerns\SeeksByCursor;
use App\Http\Controllers\Concerns\ScopesToOwnedVehicles;
use App\Http\Controllers\Controller;
use App\Models\Mpesa;
use App\Services\Payments\PaymentSource;
use App\Models\Scopes\FinancierScope;
use App\Services\Sql\LikeSql;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MpesaAPIController extends Controller
{
<<<<<<< HEAD
    use PaginatesResults;
    use ResolvesDateRange;
    use SeeksByCursor;
    use ScopesToOwnedVehicles;
=======
    use PaginatesResults, ResolvesDateRange, SeeksByCursor;

    /** See TransactionsAPIController::COUNT_TTL. */
    private const COUNT_TTL = 60;
>>>>>>> 65f0f2dc (perf(money): page the two big listings by seek, and stop recounting per page)

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function getTransactions(Request $request)
    {

        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
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
            $mpesa = $mpesa->where(function ($query) use ($request) {
                $query->where('TransID', LikeSql::op(), '%'.$request->search.'%')
                    ->orWhere('FirstName', LikeSql::op(), '%'.$request->search.'%')
                    ->orWhere('MiddleName', LikeSql::op(), '%'.$request->search.'%')
                    ->orWhere('LastName', LikeSql::op(), '%'.$request->search.'%');
                $query->orWhereHas('transaction.vehicle', function ($q) use ($request) {
                    $q->where('plate', LikeSql::op(), '%'.$request->search.'%');
                })->orWhereHas('transaction.vehicle.sacco', function ($q) use ($request) {
                    $q->where('name', LikeSql::op(), '%'.$request->search.'%');
                });
            });
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
<<<<<<< HEAD
        // BEFORE pageMeta, so the pager counts the rows the filter leaves.
        $this->narrowToSource($mpesa, $source);
=======
        // Capped: an exact count here cost 141ms for one day and 1,929ms for
        // thirty, for a number the UI renders as "of N".
        $__meta = $this->pageMeta($mpesa, $request, 20, self::COUNT_TTL);
>>>>>>> 65f0f2dc (perf(money): page the two big listings by seek, and stop recounting per page)

        $__meta = $this->pageMeta($mpesa, $request, 20);
        $usingCursor = filled($request->input('cursor'));
        $mpesa = $this->applyCursor($mpesa, $request, 'mpesas.TransTime', 'mpesas.id');
        $mpesa = $this->orderForCursor($mpesa, 'mpesas.TransTime', 'mpesas.id')
            ->skip($usingCursor ? 0 : $offset)->take(20)->get();

        $this->markSource($mpesa);

        return response()->json(array_merge([
            'mpesa' => $mpesa,
            'next_cursor' => $this->nextCursor($mpesa->all(), 'TransTime', 'id', 20),
        ], $__meta));
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
