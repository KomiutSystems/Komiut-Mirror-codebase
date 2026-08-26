<?php

namespace App\Http\Controllers\APIs\Dashboard\Saccos;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Models\FarePeriod;
use App\Models\RouteFare;
use App\Models\Scopes\SaccoScope;
use App\Services\Fares\FareResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * @group SACCO fares
 *
 * SACCOs price their own routes. A fare is per pickup→dropoff pair; where no
 * pair is set, the flat `sacco_routes.amount` applies. Editing here invalidates
 * the fare cache so the new price is live immediately.
 */
class SaccoFaresAPIController extends Controller
{
    use PaginatesResults, ResolvesTenant;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * List stop-pair fares
     *
     * @authenticated
     *
     * @queryParam sacco_id integer Filter to one SACCO. Example: 1
     * @queryParam route_id integer Filter to one route. Example: 5
     */
    public function getFares(Request $request)
    {
        $offset = max(0, ((int) $request->page) - 1) * 20;
        $fares = RouteFare::with(['route.from', 'route.to', 'fromPlace', 'toPlace', 'sacco']);
        if ($request->sacco_id > 0) {
            $fares = $fares->where('sacco_id', (int) $request->sacco_id);
        }
        if ($request->route_id > 0) {
            $fares = $fares->where('route_id', (int) $request->route_id);
        }
        $__meta = $this->pageMeta($fares, $request, 20);
        $fares = $fares->skip($offset)->take(20)->orderBy('created_at', 'DESC')->get();

        return response()->json(array_merge(['fares' => $fares], $__meta));
    }

    /**
     * Create or update a fare
     *
     * Idempotent on the (sacco, route, from, to) pair — call it again to change
     * the price. SACCO admins may omit sacco_id (defaults to their own SACCO).
     *
     * @authenticated
     *
     * @bodyParam sacco_id integer The SACCO; defaults to the caller's SACCO. Example: 1
     * @bodyParam route_id integer required The route. Example: 5
     * @bodyParam from_place_id integer required Pickup stop (place) id. Example: 12
     * @bodyParam to_place_id integer required Dropoff stop (place) id. Example: 18
     * @bodyParam amount number required Fare in KES. Example: 120
     * @bodyParam status boolean Whether the fare is active. Example: true
     */
    public function addFare(Request $request, FareResolver $resolver)
    {
        // Never the payload's SACCO for a non-superadmin. updateOrCreate's SELECT
        // is scoped, so a foreign sacco_id could never MATCH the victim's fare —
        // it just INSERTED a new one owned by them, setting the price they charge.
        $saccoId = $this->resolveSaccoId($request);
        if ($saccoId === null) {
            return $this->foreignSaccoDenied();
        }

        $validator = Validator::make(array_merge($request->all(), ['sacco_id' => $saccoId]), [
            'sacco_id' => 'required|integer|min:1',
            'route_id' => 'required|integer|min:1|exists:routes,id',
            'from_place_id' => 'required|integer|min:1|exists:places,id',
            'to_place_id' => 'required|integer|min:1|exists:places,id|different:from_place_id',
            'amount' => 'required|numeric|min:0',
            'status' => 'boolean|nullable',
            // NULL (or absent) = the base fare, charged outside every window.
            // Naming a period prices this same segment for that window instead.
            // Scoped by the model, so a SACCO cannot price against another
            // SACCO's period — exists: alone would let them.
            'fare_period_id' => 'nullable|integer|min:1',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $periodId = $request->filled('fare_period_id') ? (int) $request->input('fare_period_id') : null;

        if ($periodId !== null) {
            // Ownership, not just existence. A period is a commercial decision
            // belonging to one SACCO; pricing against someone else's would make
            // this SACCO's fares move whenever they edited their rush hour.
            $ownsPeriod = FarePeriod::withoutGlobalScope(SaccoScope::class)
                ->where('id', $periodId)->where('sacco_id', $saccoId)->exists();

            if (! $ownsPeriod) {
                return response()->json(['error' => 'That fare period does not belong to your SACCO.'], 403);
            }
        }

        $fare = RouteFare::updateOrCreate(
            [
                'sacco_id' => $saccoId,
                'route_id' => (int) $request->route_id,
                'from_place_id' => (int) $request->from_place_id,
                'to_place_id' => (int) $request->to_place_id,
                // Part of the KEY, not the payload: the base fare and each
                // period's fare are separate rows for the same segment, which is
                // what the two partial unique indexes enforce.
                'fare_period_id' => $periodId,
            ],
            [
                'amount' => (float) $request->amount,
                'status' => $request->has('status') ? (bool) $request->status : true,
            ],
        );

        $resolver->forget($saccoId, (int) $request->route_id);

        return response()->json(['success' => 'Fare saved.', 'fare' => $fare]);
    }

    /**
     * Delete a fare
     *
     * @authenticated
     *
     * @bodyParam id integer required The route_fares row id. Example: 3
     */
    public function deleteFare(Request $request, FareResolver $resolver)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|integer|min:1|exists:route_fares,id',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        // Ownership is checked EXPLICITLY rather than left to SaccoScope.
        //
        // Two reasons the scope is not enough here. RouteFare opts into
        // cross-tenant browsing, so for a caller with a NULL sacco_id the scope
        // steps aside entirely and find() would return any SACCO's fare — a
        // delete-anything hole for anyone holding 'Edit Fares' without a home
        // SACCO. And for a caller who DOES have one, the scoped find() returns
        // null while `exists:route_fares,id` has already passed, so the endpoint
        // answered 200 'Fare removed.' having removed nothing.
        $saccoId = $this->resolveSaccoId($request);
        if ($saccoId === null) {
            return $this->foreignSaccoDenied();
        }

        $fare = RouteFare::withoutGlobalScope(SaccoScope::class)
            ->where('id', (int) $request->id)
            ->where('sacco_id', $saccoId)
            ->first();

        if ($fare === null) {
            return response()->json(['error' => 'That fare is not yours to remove.'], 404);
        }

        $routeId = (int) $fare->route_id;
        $fare->delete();
        $resolver->forget($saccoId, $routeId);

        return response()->json(['success' => 'Fare removed.']);
    }
}
