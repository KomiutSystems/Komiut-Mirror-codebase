<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Dashboard\Mpesa;

use App\Http\Controllers\Controller;
use App\Models\Mpesa;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read endpoints for the M-Pesa payments web dashboard: the Tills list and the
 * summary tiles. Everything is SACCO-scoped — Vehicle and Transaction carry
 * SaccoScope, and the user count is confined explicitly (User has no scope).
 */
class MpesaDashboardController extends Controller
{
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
                    $inner->where('plate', 'LIKE', $like)
                        ->orWhere('till_number', 'LIKE', $like)
                        ->orWhere('merchant_short_code', 'LIKE', $like);
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
        $today = Carbon::today();

        $mpesaToday = (float) Transaction::whereBetween('trans_date', [$today, $today->copy()->addDay()])
            ->where('mpesa_id', '>', 0)
            ->sum('amount');

        $tillsCount = Vehicle::where(function ($q) {
            $q->whereNotNull('till_number')->orWhereNotNull('merchant_short_code');
        })->count();

        $usersCount = $this->scopedUserCount($request);

        $recent = Mpesa::with('transaction.vehicle')
            ->when($this->saccoConstraint($request), function ($q, $saccoId) {
                $q->whereHas('transaction.vehicle', fn ($v) => $v->where('sacco_id', $saccoId));
            })
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

    /** Users in the caller's SACCO; all users for a superadmin. */
    private function scopedUserCount(Request $request): int
    {
        $saccoId = $this->saccoConstraint($request);

        return $saccoId === null
            ? User::count()
            : User::where('sacco_id', $saccoId)->count();
    }

    /** The SACCO id to confine reads to, or null for a superadmin (unconstrained). */
    private function saccoConstraint(Request $request): ?int
    {
        $user = $request->user();
        if ($user->isSuperAdmin()) {
            return null;
        }

        $own = $user->currentSaccoId();

        return $own !== null ? (int) $own : null;
    }
}
