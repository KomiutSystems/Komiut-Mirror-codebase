<?php

namespace App\Http\Controllers\APIs\Dashboard\BookARide;

use App\Http\Controllers\Controller;
use App\Services\Fares\FareResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * @group Book a ride
 */
class FareAPIController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Get the fare for a trip
     *
     * Returns the SACCO-set price for a route between two stops. This value is
     * authoritative: it is exactly what the booking and the STK push will charge,
     * so the app should display it and never compute its own. Served from cache,
     * so it is cheap to call on every price preview.
     *
     * @authenticated
     *
     * @queryParam sacco_id integer required The SACCO operating the trip (from the selected vehicle). Example: 1
     * @queryParam route_id integer required The route id. Example: 5
     * @queryParam from_id integer The pickup stop (place) id. Omit to get the route's flat fare. Example: 12
     * @queryParam to_id integer The dropoff stop (place) id. Example: 18
     *
     * @response 200 {"fare": {"amount": 120, "currency": "KES", "sacco_id": 1, "route_id": 5, "from_id": 12, "to_id": 18, "is_peak": false, "period": null}}
     * @response 404 {"error": "No fare is set for this route yet."}
     */
    public function getFare(Request $request, FareResolver $fares)
    {
        $validator = Validator::make($request->all(), [
            'sacco_id' => 'required|integer|min:1',
            'route_id' => 'required|integer|min:1',
            'from_id' => 'integer|min:1|nullable',
            'to_id' => 'integer|min:1|nullable',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $fromId = $request->filled('from_id') ? (int) $request->from_id : null;
        $toId = $request->filled('to_id') ? (int) $request->to_id : null;

        // quote(), not resolve(): the same number, plus where it came from. A
        // bare float cannot distinguish "this leg is priced at 60/=" from "this
        // leg is not priced, so here is the whole-route 150/=" — and those are
        // very different things to show a passenger boarding at Ruiru.
        $quote = $fares->quote((int) $request->sacco_id, (int) $request->route_id, $fromId, $toId);

        if ($quote['amount'] === null) {
            return response()->json(['error' => 'No fare is set for this route yet.'], 404);
        }

        return response()->json(['fare' => [
            'amount' => $quote['amount'],
            'currency' => 'KES',
            'sacco_id' => (int) $request->sacco_id,
            'route_id' => (int) $request->route_id,
            'from_id' => $fromId,
            'to_id' => $toId,

            // WHY this price, not just what. A passenger quoted 200/= at 7am and
            // 150/= at 11am will otherwise conclude the app is broken or the
            // SACCO is cheating. Null outside every window — the ordinary case.
            'is_peak' => $quote['period'] !== null,
            'period' => $quote['period'],

            // WHERE it came from: peak_pair | pair | flat. `is_fallback` is the
            // one the client should act on — it means this exact leg has no
            // price and the whole-route fare is standing in for it, which
            // overcharges anyone not riding the full run. The app should not
            // present a fallback as a settled price, and the dashboard should
            // show the SACCO which legs are still unpriced.
            'source' => $quote['source'],
            'is_fallback' => $quote['is_fallback'],
        ]]);
    }
}
