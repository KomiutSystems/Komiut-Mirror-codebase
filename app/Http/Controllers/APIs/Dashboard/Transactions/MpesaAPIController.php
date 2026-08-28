<?php

namespace App\Http\Controllers\APIs\Dashboard\Transactions;

use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Concerns\ScopesToOwnedVehicles;
use App\Http\Controllers\Controller;
use App\Models\Mpesa;
use App\Services\Payments\PaymentSource;
use App\Models\Scopes\FinancierScope;
use App\Services\Sql\LikeSql;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MpesaAPIController extends Controller
{
    use PaginatesResults;
    use ScopesToOwnedVehicles;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function getTransactions(Request $request)
    {

        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $from_date = $request->date != '' ? Carbon::parse($request->date) : Carbon::today();
        $to_date = $from_date->copy()->addDays(1);
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
            ->whereBetween('TransTime', [$from_date, $to_date]);

        // Tenancy, in three tiers, and the ORDER is load-bearing.
        //
        // Mpesa now carries BelongsToSacco with $saccoVia = 'transaction.vehicle',
        // so a SACCO user needs nothing here at all — the manual whereHas this
        // replaces emitted exactly what SaccoScope already emits, a second
        // correlated EXISTS over a 1.3M-row table for no extra confinement.
        //
        // What SaccoScope does NOT do is fail closed: it returns early on a null
        // sacco_id, so the old `&& $user->currentSaccoId()` guard let a saccoless
        // holder of 'View Transactions' read every SACCO's payments.
        //
        // The bank tier must be tested BEFORE that fail-closed rule, because a
        // bank user is saccoless by design — a bank is not a SACCO. Reversing
        // the two would lock the banks out of the one screen the feature exists
        // for. Superadmins stay unconstrained; the ?sacco filter below lets them
        // narrow to one.
        $user = auth()->user();
        if ($user && ! $user->isSuperAdmin()) {
            if (FinancierScope::confines($user)) {
                // Nothing to add: Mpesa carries BelongsToFinancier with the same
                // 'transaction.vehicle' path, so the global scope has already
                // constrained this query. Repeating it here emitted a SECOND
                // identical correlated EXISTS over 1.3M rows. The branch stays
                // because it is what stops a bank — saccoless by design — from
                // falling into the tenantless deny below.
            } elseif ($user->currentSaccoId() === null) {
                // Neither a bank nor in a SACCO: none of this money is theirs.
                $mpesa = $mpesa->whereRaw('1 = 0');
            }
            // Otherwise SaccoScope has already confined the query.
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
        // Only filter when a term was actually typed.
        //
        // This exact block took komiut.com down for ~6 hours on 2026-08-07. With an
        // empty search box it becomes LIKE '%%' on four varchar columns OR'd with
        // two correlated EXISTS subqueries reaching into transactions (20.5M rows)
        // and vehicles/saccos. Nothing in that OR-group is indexable, so searching
        // for NOTHING was the most expensive query in the application — roughly six
        // hours per request. Each request held one php-fpm worker until all 20 were
        // gone, and nginx returned 504 to every user.
        //
        // The guard must wrap the WHOLE group. Guarding only the first column leaves
        // the orWhere siblings matching unconditionally, which is worse than no
        // guard at all.
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
            // mpesas.TransAmount is a VARCHAR holding a money value, so the previous
            // whereBetween compared it as TEXT: searching 100 never matched a stored
            // "100.00", and any range comparison would order "9" above "100".
            // Compare numerically instead.
            //
            // The identifier is wrapped by the grammar because the column name is
            // mixed-case and PostgreSQL folds unquoted identifiers to lower case;
            // NULLIF guards the empty strings PostgreSQL refuses to cast.
            $column = DB::connection()->getQueryGrammar()->wrap('TransAmount');
            $mpesa = $mpesa->whereRaw(
                "CAST(NULLIF({$column}, '') AS DECIMAL(15,2)) = ?",
                [(float) $request->amount]
            );
        }
        // BEFORE pageMeta, so the pager counts the rows the filter leaves.
        $this->narrowToSource($mpesa, $source);

        $__meta = $this->pageMeta($mpesa, $request, 20);
        $mpesa = $mpesa->orderBy('TransTime', 'DESC')->skip($offset)->take(20)->get();

        $this->markSource($mpesa);

        return response()->json(array_merge(['mpesa' => $mpesa], $__meta));
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
