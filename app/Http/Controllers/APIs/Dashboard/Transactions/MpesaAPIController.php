<?php

namespace App\Http\Controllers\APIs\Dashboard\Transactions;

use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Concerns\ScopesToOwnedVehicles;
use App\Http\Controllers\Controller;
use App\Models\Mpesa;
use App\Models\Scopes\FinancierScope;
use App\Services\Sql\LikeSql;
use Carbon\Carbon;
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
        $__meta = $this->pageMeta($mpesa, $request, 20);
        $mpesa = $mpesa->orderBy('TransTime', 'DESC')->skip($offset)->take(20)->get();

        return response()->json(array_merge(['mpesa' => $mpesa], $__meta));
    }
}
