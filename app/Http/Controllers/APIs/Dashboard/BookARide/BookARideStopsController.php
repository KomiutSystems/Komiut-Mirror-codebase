<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Dashboard\BookARide;

use App\Http\Controllers\Controller;
use App\Models\Place;
use App\Services\Geo\GeoDistance;
use App\Services\Sql\LikeSql;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @group Book a ride
 *
 * The stops a passenger can actually pick between.
 *
 * WHY THIS EXISTS. book_a_ride/routes is a POINT-FIRST search — the passenger
 * names a pickup and a dropoff and gets the routes that serve that segment, in
 * that direction. It takes `from_place_id` and `to_place_id`. But the only way
 * to discover a place id was GET routes/places, which is gated on the
 * `View Places` permission, and a passenger holds no permissions at all.
 *
 * So the first step of the passenger journey had no endpoint. The app could
 * search routes only if it already knew two ids it had no way to learn.
 *
 * ONLY REAL STOPS. `places` is 1,980 rows for 120 distinct names, of which 936
 * are literally called "Boarding Terminal not provided" and every single row has
 * NULL coordinates. Showing a passenger that list would be worse than showing
 * nothing. This returns places that are actually ON a route — a stop somebody
 * can board at — which is both the useful set and a small one.
 */
class BookARideStopsController extends Controller
{
    /** A type-ahead, not a gazetteer. */
    private const MAX_RESULTS = 25;

    public function __construct()
    {
        // auth:sanctum and NOTHING else. A permission check here is what broke
        // the journey in the first place: this is the passenger's first call,
        // and passengers hold no permissions.
        $this->middleware('auth:sanctum');
    }

    /**
     * Stops you can travel between
     *
     * Every place that is a stop on at least one active route. Pass `search` to
     * filter as the passenger types, or `near` coordinates to sort by proximity
     * so "where am I leaving from" answers itself.
     *
     * @authenticated
     *
     * @queryParam search string Filter by name. Example: Thika
     * @queryParam latitude number Sort by distance from here. Example: -1.2864
     * @queryParam longitude number Sort by distance from here. Example: 36.8172
     * @queryParam from_place_id integer Only stops reachable ONWARD from this one. Example: 12
     *
     * @response 200 {"stops": [{"id": 12, "name": "Nairobi CBD", "county": "Nairobi", "latitude": -1.2864, "longitude": 36.8172, "km_away": 0.4}]}
     */
    public function index(Request $request): JsonResponse
    {
        $query = Place::query()
            ->select(['places.id', 'places.name', 'places.county_name', 'places.latitude', 'places.longitude'])
            ->where('places.status', true)
            // Only places that are a stop on a live route. This is what keeps
            // the 936 "Boarding Terminal not provided" rows out.
            ->whereExists(function ($q): void {
                $q->select(DB::raw(1))
                    ->from('route_stages')
                    ->join('routes', 'routes.id', '=', 'route_stages.route_id')
                    ->whereColumn('route_stages.place_id', 'places.id')
                    ->where('routes.status', 1)
                    ->where('route_stages.status', true);
            });

        if (filled($request->input('search'))) {
            $query->where('places.name', LikeSql::op(), '%'.$request->input('search').'%');
        }

        // ONWARD ONLY. Once the passenger has chosen a pickup, offering stops
        // that no route reaches after it produces a search that returns nothing
        // and looks broken. This mirrors the pickup-before-dropoff test that
        // book_a_ride/routes itself applies.
        if ($request->filled('from_place_id')) {
            $fromId = (int) $request->input('from_place_id');

            $query->whereExists(function ($q) use ($fromId): void {
                $q->select(DB::raw(1))
                    ->from('route_stages as pickup')
                    ->join('route_stages as dropoff', function ($join): void {
                        $join->on('dropoff.route_id', '=', 'pickup.route_id')
                            ->on('pickup.distance', '<', 'dropoff.distance');
                    })
                    ->where('pickup.place_id', $fromId)
                    ->whereColumn('dropoff.place_id', 'places.id');
            });
        }

        $stops = $query->orderBy('places.name')->limit(200)->get();

        $lat = $request->filled('latitude') ? (float) $request->input('latitude') : null;
        $lng = $request->filled('longitude') ? (float) $request->input('longitude') : null;

        $rows = $stops->map(function (Place $p) use ($lat, $lng): array {
            // Cast explicitly: these models declare no float cast, so on
            // PostgreSQL a `double precision` arrives as a STRING and a map
            // client would have to parseFloat its way around our column types.
            $pLat = $p->latitude !== null ? (float) $p->latitude : null;
            $pLng = $p->longitude !== null ? (float) $p->longitude : null;

            $km = null;
            if ($lat !== null && $lng !== null && $pLat !== null && $pLng !== null) {
                $km = round(GeoDistance::km($lat, $lng, $pLat, $pLng), 2);
            }

            return [
                'id' => (int) $p->id,
                'name' => $p->name,
                'county' => $p->county_name,
                'latitude' => $pLat,
                'longitude' => $pLng,
                'km_away' => $km,
            ];
        });

        if ($lat !== null && $lng !== null) {
            // Stops with no coordinates sort last rather than first — a NULL is
            // "we don't know where this is", not "it is nought km away".
            $rows = $rows->sortBy(fn (array $r) => $r['km_away'] ?? INF)->values();
        }

        return response()->json([
            'stops' => $rows->take(self::MAX_RESULTS)->values(),
            'total' => $rows->count(),
        ]);
    }
}
