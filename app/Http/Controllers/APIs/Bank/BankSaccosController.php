<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Bank;

use App\Http\Controllers\Controller;
use App\Models\Sacco;
use App\Models\Scopes\FinancierScope;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Bank
 *
 * The SACCOs a bank's financed buses belong to -- the bank's own SACCO filter.
 *
 * A bank reads its fleet SACCO by SACCO, but `saccos` is the platform's list
 * (View Saccos, which a Bank Viewer does not hold), and Sacco carries no bank
 * boundary of its own: SaccoScope and BrandScope both step aside for a bank.
 * So the list is derived from the bank's VEHICLES, which FinancierScope does
 * confine -- a SACCO appears here only because a bus this bank finances is in
 * it, and its count is of those buses alone. NICCO MOVERS banks with both NCBA
 * and Co-op; each bank sees NICCO with its own number of buses, never the
 * other's.
 */
class BankSaccosController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * SACCOs of my financed buses
     *
     * @authenticated
     *
     * @response 200 {"saccos": [{"id": 4, "name": "NICCO MOVERS LIMITED", "vehicles": 60}]}
     * @response 403 {"error": "This list is for bank users."}
     */
    public function index(Request $request): JsonResponse
    {
        if (! FinancierScope::confines($request->user())) {
            return response()->json(['error' => 'This list is for bank users.'], 403);
        }

        // Vehicle::query() carries FinancierScope: this bank's buses only.
        $counts = Vehicle::query()
            ->whereNotNull('vehicles.sacco_id')
            ->toBase()
            ->select('vehicles.sacco_id')
            ->selectRaw('COUNT(*) AS vehicles_count')
            ->groupBy('vehicles.sacco_id')
            ->pluck('vehicles_count', 'sacco_id');

        $saccos = Sacco::withoutGlobalScopes()
            ->whereIn('id', $counts->keys()->all())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Sacco $s) => ['id' => (int) $s->id, 'name' => $s->name, 'vehicles' => (int) $counts->get($s->id)])
            ->values();

        return response()->json(['saccos' => $saccos]);
    }
}
