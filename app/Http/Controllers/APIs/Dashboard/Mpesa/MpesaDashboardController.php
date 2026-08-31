<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Dashboard\Mpesa;

use App\Http\Controllers\Concerns\ScopesToOwnedVehicles;
use App\Http\Controllers\Controller;
use App\Models\Mpesa;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Sql\LikeSql;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read endpoints for the M-Pesa payments web dashboard: the Tills list and the
 * summary tiles. Everything is SACCO-scoped — Vehicle, Transaction and Mpesa
 * carry SaccoScope, and the user count is confined explicitly (User has no
 * scope).
 *
 * Three tiers, not two. SaccoScope exempts BOTH a superadmin and a user with no
 * home SACCO, so "scoped by the model" silently means "not scoped at all" for
 * the second group. Both endpoints therefore ask isTenantless() first and
 * return an empty payload, instead of letting a saccoless account fall through
 * to the same unconstrained reads a superadmin gets.
 */
class MpesaDashboardController extends Controller
{
    use ScopesToOwnedVehicles;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * The Tills tab: each vehicle with a till/merchant configured, plus its
     * SACCO's shared paybill. Paginated.
     */
    public function tills(Request $request): JsonResponse
    {
        // Fail closed before touching a query. Vehicle carries SaccoScope, but
        // that scope does not apply to a user with no home SACCO — so without
        // this a saccoless non-super holding 'View Payment Settings' read every
        // SACCO's tills, and the `coverage` block below (a GROUP BY financier
        // with no caller constraint of its own) handed them both banks'
        // platform-wide vehicle and till totals.
        if ($this->isTenantless($request)) {
            return $this->emptyTills();
        }

        $search = trim((string) $request->input('search', ''));

        // ?financier=NCBA | coop-bank  — the two banks reconcile separately, so
        // "which of these tills are ours" has to be answerable directly.
        $financier = trim((string) $request->input('financier', ''));

        // ?missing=1 lists vehicles with NO till at all. That is the set the
        // banks actually chase: a vehicle carrying passengers with nowhere for
        // its money to land collects nothing, and it is invisible on a screen
        // that only ever shows configured tills.
        $missing = $request->boolean('missing');

        $query = Vehicle::with('sacco.mpesa_payment')
            ->when($financier !== '', fn ($q) => $q->where('financier', $financier))
            ->when($missing,
                fn ($q) => $q->whereNull('till_number')->whereNull('merchant_short_code'),
                fn ($q) => $q->where(function ($inner) {
                    $inner->whereNotNull('till_number')->orWhereNotNull('merchant_short_code');
                }))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.$search.'%';
                $q->where(function ($inner) use ($like) {
                    $inner->where('plate', LikeSql::op(), $like)
                        ->orWhere('till_number', LikeSql::op(), $like)
                        ->orWhere('merchant_short_code', LikeSql::op(), $like);
                });
            })
            ->orderBy('created_at', 'DESC');

        $page = $query->paginate(20);

        $tills = collect($page->items())->map(fn (Vehicle $v) => [
            'vehicle_id' => $v->id,
            'plate' => $v->plate,
            'till_number' => $v->till_number,
            'merchant_short_code' => $v->merchant_short_code,
            'financier' => $v->financier,
            // The BANK's collection accounts, which are what each bank
            // reconciles against -- the Safaricom till above is not.
            'ncba_till' => $v->ncba_till,
            'coop_till' => $v->coop_till,
            'paybill' => optional($v->sacco?->mpesa_payment)->paybill,
            'status' => (bool) $v->status,
        ]);

        // Coverage per bank, independent of the current page or filter: how many
        // vehicles each financier has, and how many of those can actually take
        // money. This is the number a bank meeting opens with.
        $coverage = Vehicle::selectRaw('financier')
            ->selectRaw('COUNT(*) as vehicles')
            ->selectRaw('SUM(CASE WHEN till_number IS NOT NULL OR merchant_short_code IS NOT NULL THEN 1 ELSE 0 END) as with_till')
            ->groupBy('financier')
            ->get()
            ->map(fn ($r) => [
                'financier' => $r->financier ?? 'unassigned',
                'vehicles' => (int) $r->vehicles,
                'with_till' => (int) $r->with_till,
                'without_till' => (int) $r->vehicles - (int) $r->with_till,
            ]);

        return response()->json([
            'tills' => $tills,
            'coverage' => $coverage,
            'count' => $tills->count(),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'total_pages' => $page->lastPage(),
        ]);
    }

    /** The dashboard tiles: today's M-Pesa collection, till count, user count, recent payments. */
    public function stats(Request $request): JsonResponse
    {
        // Same fail-closed gate as tills(): Transaction, Vehicle and Mpesa are
        // all SACCO-scoped through a vehicle, and none of those scopes applies
        // to a user with no home SACCO. Every tile below would otherwise be the
        // platform-wide figure — which is exactly the superadmin view.
        if ($this->isTenantless($request)) {
            return response()->json([
                'mpesa_today' => 0.0,
                'tills_count' => 0,
                'users_count' => 0,
                'recent_transactions' => [],
            ]);
        }

        $today = Carbon::today();

        // An investor holds View Transactions, which gates this endpoint, and
        // these are the landing tiles: today's takings and the ten most recent
        // payments. Unnarrowed they show an investor NICCO's entire daily M-Pesa
        // figure and other people's payers by name.
        //
        // NULL means "not investor-only" and changes nothing for anyone else. An
        // EMPTY array is passed through UNGATED and compiles to 0 = 1, because an
        // investor who owns no bus must see no money.
        $ownedVehicleIds = $this->ownedVehicleIds();

        // attributed(): a payment matched to no bus is not takings. A SACCO
        // caller never saw these (sacco_id is reached through the vehicle), but
        // a superadmin read is unscoped and picked up the nightly sweeps.
        $mpesaToday = (float) Transaction::attributed()
            ->whereBetween('trans_date', [$today, $today->copy()->addDay()])
            ->where('mpesa_id', '>', 0)
            ->when($ownedVehicleIds !== null, fn ($q) => $q->whereIn('vehicle_id', (array) $ownedVehicleIds))
            ->sum('amount');

        // Tills are configuration, not money — but an investor has no business
        // counting the SACCO's whole fleet either, and the number sits on the
        // same tile row as the takings.
        $tillsCount = Vehicle::where(function ($q) {
            $q->whereNotNull('till_number')->orWhereNotNull('merchant_short_code');
        })
            ->when($ownedVehicleIds !== null, fn ($q) => $q->whereIn('id', (array) $ownedVehicleIds))
            ->count();

        $usersCount = $this->scopedUserCount($request);

        // No manual whereHas here any more: Mpesa now carries SaccoScope via
        // 'transaction.vehicle', which applies the identical constraint (and
        // skips it for a superadmin). Repeating it emitted a second correlated
        // EXISTS over a 1.3M-row table for the same answer.
        $recent = Mpesa::with('transaction.vehicle')
            // Ownership is one hop out: mpesas carries no vehicle_id, so it is
            // checked through the transaction that matched the payment. A
            // payment nothing ever matched has no owner and correctly drops out.
            ->when($ownedVehicleIds !== null, fn ($q) => $q->whereHas(
                'transaction',
                fn ($t) => $t->whereIn('vehicle_id', (array) $ownedVehicleIds)
            ))
            ->orderBy('TransTime', 'DESC')
            ->take(10)
            ->get()
            ->map(fn (Mpesa $m) => [
                'trans_id' => $m->TransID,
                'name' => trim($m->FirstName.' '.$m->LastName),
                'vehicle' => optional($m->transaction?->vehicle)->plate,
                'msisdn' => $m->MSISDN,
                'amount' => (float) $m->TransAmount,
                'paybill' => $m->BusinessShortCode,
                'merchant' => $m->BillRefNumber,
                'date' => $m->TransTime,
            ]);

        return response()->json([
            'mpesa_today' => $mpesaToday,
            'tills_count' => $tillsCount,
            'users_count' => $usersCount,
            'recent_transactions' => $recent,
        ]);
    }

    /** Users in the caller's SACCO; all users for a superadmin; none for a saccoless caller. */
    private function scopedUserCount(Request $request): int
    {
        if ($this->seesEverySacco($request)) {
            return User::count();
        }

        $saccoId = $this->saccoConstraint($request);

        // No SACCO, not a superadmin: there is no tenant to count. Returning
        // User::count() here handed a passenger or an unassigned staff account
        // the platform-wide user total.
        return $saccoId === null ? 0 : User::where('sacco_id', $saccoId)->count();
    }

    /**
     * The SACCO id to confine reads to, or null when there is nothing to
     * confine BY.
     *
     * Read together with seesEverySacco(): null from this method used to mean
     * two opposite things — "superadmin, show everything" and "this user has no
     * SACCO" — and every caller took the first reading, so a saccoless non-super
     * account was served superadmin-shaped data. The two questions are now
     * asked separately and "no SACCO" fails closed.
     */
    private function saccoConstraint(Request $request): ?int
    {
        $own = $request->user()->currentSaccoId();

        return $own !== null ? (int) $own : null;
    }

    /** Only the platform tier reads across SACCOs. */
    private function seesEverySacco(Request $request): bool
    {
        return $request->user()->isSuperAdmin();
    }

    /**
     * A caller with no SACCO who is not a superadmin: there is no tenant whose
     * data they could be shown. Both global scopes skip such a user, so every
     * endpoint here has to say "nothing" explicitly rather than inherit it.
     */
    private function isTenantless(Request $request): bool
    {
        return ! $this->seesEverySacco($request) && $this->saccoConstraint($request) === null;
    }

    /** The tills payload shape, with nothing in it. */
    private function emptyTills(): JsonResponse
    {
        return response()->json([
            'tills' => [],
            'coverage' => [],
            'count' => 0,
            'total' => 0,
            'page' => 1,
            'per_page' => 20,
            'total_pages' => 1,
        ]);
    }
}
