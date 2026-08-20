<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Driver;

use App\Http\Controllers\Concerns\ResolvesDriverVehicle;
use App\Http\Controllers\Controller;
use App\Models\SaccoRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Driver — the routes a crew can serve
 *
 * A driver picks which of their SACCO's routes to run today. The dashboard's
 * `saccos/routes` is 403 for a driver, and the brand-wide `routes` list ignores
 * the SACCO entirely — so a crew either saw nothing or every other SACCO's
 * routes. This screen is keyed on the vehicle the caller is currently assigned
 * to, so the assignment IS the boundary: they see their own SACCO's routes and
 * no one else's.
 */
class DriverRoutesController extends Controller
{
    use ResolvesDriverVehicle;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * The routes this SACCO operates
     *
     * Scoped strictly to the assigned vehicle's `sacco_id`. SaccoRoute is
     * SaccoScoped, but the global scope keys on the authenticated user's SACCO,
     * which is not necessarily the vehicle's — so the scope is dropped and the
     * boundary re-stated explicitly against the vehicle's SACCO.
     */
    public function index(Request $request): JsonResponse
    {
        $vehicle = $this->vehicle();
        if ($vehicle === null) {
            return $this->noAssignment();
        }

        $routes = SaccoRoute::withoutGlobalScopes()
            ->with(['route.from', 'route.to'])
            ->where('sacco_id', $vehicle->sacco_id)
            ->where('status', true)
            ->whereHas('route')
            ->get()
            ->map(fn (SaccoRoute $saccoRoute) => [
                'id' => (int) $saccoRoute->route->id,
                'name' => $saccoRoute->route->name,
                'from' => [
                    'id' => optional($saccoRoute->route->from)->id,
                    'name' => optional($saccoRoute->route->from)->name,
                ],
                'to' => [
                    'id' => optional($saccoRoute->route->to)->id,
                    'name' => optional($saccoRoute->route->to)->name,
                ],
                'fare' => (float) $saccoRoute->amount,
            ])->values();

        return response()->json(['routes' => $routes]);
    }
}
