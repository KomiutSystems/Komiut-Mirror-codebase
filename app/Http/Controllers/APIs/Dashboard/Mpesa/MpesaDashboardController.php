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

        $query = Vehicle::with('sacco.mpesa_payment')
            ->where(function ($q) {
                $q->whereNotNull('till_number')->orWhereNotNull('merchant_short_code');
            })
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
            'paybill' => optional($v->sacco?->mpesa_payment)->paybill,
            'status' => (bool) $v->status,
        ]);

        return response()->json([
            'tills' => $tills,
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
