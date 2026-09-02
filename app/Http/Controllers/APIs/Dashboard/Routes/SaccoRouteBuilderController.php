<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Dashboard\Routes;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Place;
use App\Models\Route;
use App\Models\RouteStage;
use App\Models\SaccoRoute;
use App\Services\Fares\FareResolver;
use App\Services\Geo\GeoDistance;
use App\Services\Routes\RouteTerminusProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * @group Routes
 *
 * Building a SACCO's route in ONE call: the road, its stops, and what it costs.
 *
 * WHY THIS EXISTS. Creating a usable route used to take at least four calls to
 * three controllers — routes/place/add per new stop, routes/add by place NAME,
 * routes/stages/add per stop (again by name), routes/stages/coords/add per stop
 * to attach the pin — with no transaction around any of it. Half a route was a
 * normal outcome. Worse, routes/add REFUSES unless both places already exist, so
 * the obvious flow ("name two stops nobody has entered yet, drop two pins,
 * save") could not be expressed at all. A dashboard dialog offering exactly that
 * flow was therefore posting a shape no endpoint accepted.
 *
 * ORDER IS THE PAYLOAD. `stops` is the travel order, first to last. The first is
 * the origin and the last is the destination — the caller does not restate them.
 *
 * DISTANCE IS DERIVED, NEVER SUPPLIED. route_stages.distance is not decoration:
 * book_a_ride/routes decides whether a route serves a journey by testing
 * `pickup.distance < dropoff.distance`, so it is what makes a route findable and
 * what stops it being sold backwards. Letting a client send it would let a typo
 * make a route unbookable in one direction. It is computed here, cumulatively,
 * from the pins.
 *
 * (The old path wrote NULL into that column whenever the origin place had no
 * longitude — which the codebase's own comments say is every place in
 * production — against a NOT NULL column. That is a 500 on the happy path.)
 *
 * TENANCY. The route is stamped with the caller's own SACCO and nothing accepts
 * a sacco_id from the body except a superadmin acting on a SACCO's behalf. Since
 * `routes` became SACCO-owned, no SACCO can see or touch another's.
 */
class SaccoRouteBuilderController extends Controller
{
    use ResolvesTenant;

    /** A route with one stop is not a route. */
    private const MIN_STOPS = 2;

    /** Guards a pathological payload; a matatu route is tens of stops, not hundreds. */
    private const MAX_STOPS = 60;

    public function __construct(
        private readonly FareResolver $fares,
        private readonly RouteTerminusProvisioner $termini,
    )
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Create a route with its stops and its fare
     *
     * @authenticated
     *
     * @bodyParam name string The route's name. Derived from the first and last stop when omitted. Example: Nairobi CBD - Thika Main Stage
     * @bodyParam fare number required The base fare for the whole route, in KES. Example: 150
     * @bodyParam stops object[] required The stops in travel order, first to last. At least two.
     * @bodyParam stops[].place_id integer An existing stop. Give this OR name+coordinates.
     * @bodyParam stops[].name string A new stop's name. Example: Thika Main Stage
     * @bodyParam stops[].latitude number A new stop's latitude, decimal degrees. Example: -1.0396
     * @bodyParam stops[].longitude number A new stop's longitude, decimal degrees. Example: 37.09
     * @bodyParam stops[].county_name string Optional county for a new stop. Example: Kiambu
     * @bodyParam status boolean Whether the route is live. Defaults to true.
     *
     * @response 201 {"route": {"id": 1973, "name": "Nairobi CBD - Thika Main Stage", "fare": 150, "stops": []}}
     * @response 403 {"error": "You can only act on your own SACCO."}
     * @response 409 {"error": "You already run a route between these two stops."}
     */
    public function store(Request $request): JsonResponse
    {
        $saccoId = $this->resolveSaccoId($request);

        if ($saccoId === null) {
            return $this->foreignSaccoDenied();
        }

        if (! auth()->user()->can('Add Routes') && ! auth()->user()->can('Edit Routes')) {
            return response()->json(['error' => 'You do not have permission to manage routes.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:160',
            'fare' => 'required|numeric|min:0',
            'status' => 'nullable|boolean',

            'stops' => 'required|array|min:'.self::MIN_STOPS.'|max:'.self::MAX_STOPS,
            // An existing stop OR a new one. Neither is required on its own,
            // because either shape is valid; which one was given is settled per
            // stop in resolveStop(), where a useful message can name the index.
            'stops.*.place_id' => 'nullable|integer|min:1|exists:places,id',
            'stops.*.name' => 'nullable|string|max:120',
            'stops.*.county_name' => 'nullable|string|max:120',
            // Ranges are the real world's, not Kenya's: a mistyped sign is caught
            // but nothing legitimate is refused. Note these are two SEPARATE
            // NUMERIC fields — never one "-1.2858° S, 36.8286°" string. A map
            // picker hands over two numbers; anything else is a parsing problem
            // the client should solve before it reaches the API.
            'stops.*.latitude' => 'nullable|numeric|between:-90,90',
            'stops.*.longitude' => 'nullable|numeric|between:-180,180',
        ], [
            'stops.min' => 'A route needs at least two stops — where it starts and where it ends.',
            'stops.*.latitude.between' => 'Latitude must be between -90 and 90 degrees.',
            'stops.*.longitude.between' => 'Longitude must be between -180 and 180 degrees.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        try {
            $result = DB::transaction(function () use ($request, $saccoId): array {
                $stops = [];

                foreach ((array) $request->input('stops') as $index => $stop) {
                    $stops[] = $this->resolveStop((array) $stop, $index);
                }

                $placeIds = array_column($stops, 'id');

                if (count(array_unique($placeIds)) !== count($placeIds)) {
                    abort(422, 'The same stop appears twice on this route.');
                }

                $from = $stops[0];
                $to = $stops[count($stops) - 1];

                // Scoped by the model: this asks whether THIS SACCO already runs
                // this pair, not whether anyone does. Two SACCOs running
                // Nairobi–Thika is the normal case, not a collision.
                $duplicate = Route::query()
                    ->where('from_id', $from['id'])
                    ->where('to_id', $to['id'])
                    ->exists();

                if ($duplicate) {
                    abort(409, 'You already run a route between these two stops.');
                }

                $route = Route::create([
                    'sacco_id' => $saccoId,
                    'name' => $request->filled('name')
                        ? trim((string) $request->input('name'))
                        : $from['name'].' - '.$to['name'],
                    'from_id' => $from['id'],
                    'to_id' => $to['id'],
                    'status' => $request->boolean('status', true) ? 1 : 0,
                ]);

                $this->writeStages($route->id, $stops);

                // The flat fare. This is the ONLY writer of sacco_routes.amount
                // in the API — the old route-save path seeded it to 0 and there
                // was no way to change it, so tier 2 of the fare resolver was
                // permanently zero for anything created through the dashboard.
                SaccoRoute::updateOrCreate(
                    ['sacco_id' => $saccoId, 'route_id' => $route->id],
                    [
                        'user_id' => auth()->id(),
                        'amount' => (float) $request->input('fare'),
                        // Dead column, NOT NULL, no default. Written so the
                        // insert succeeds; nothing reads it.
                        'min_amount' => 0,
                        'status' => true,
                    ]
                );

                // A route nobody can depart from is not a route. `queues`
                // requires a terminus whose place IS the route's origin -- both
                // the driver and the dispatcher enforce that, and it is a NOT
                // NULL column, so a missing one fails as a 422 rather than
                // degrading. Before this, every route built here was born
                // unqueueable: route 1973 had four stops, a fare and no way to
                // run a single trip on it.
                //
                // Two rows, because the schema splits the stage itself from the
                // SACCOs that work out of it. `sacco_termini` had ZERO rows
                // across all 48 SACCOs after three years, because the only
                // writer is a superadmin-only console -- so a SACCO admin
                // building their own route could not attach one either.
                // BOTH ends, not just the origin. A route has two termini: the
                // bus departs from one and turns round at the other. Provisioning
                // only the origin left every destination -- Thika, Ngong, Alsops
                // -- with no stage to queue at, so a crew reaching the far end
                // had nowhere to join a queue for the return leg and the driver's
                // terminus picker showed two entries for three routes.
                $terminus = $this->termini->ensureFor($from['id'], $saccoId, auth()->id());
                $this->termini->ensureFor($to['id'], $saccoId, auth()->id());

                return ['route' => $route, 'stops' => $stops, 'terminus' => $terminus];
            });
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json(['error' => $e->getMessage()], $e->getStatusCode());
        }

        $this->fares->forget($saccoId, (int) $result['route']->id);

        return response()->json([
            'route' => $this->present($result['route'], $result['stops'], (float) $request->input('fare')),
        ], 201);
    }

    /**
     * A stop, either looked up or created.
     *
     * NEW STOPS REQUIRE COORDINATES. Every one of the 1,980 places already in
     * production has NULL lat/lng, which is why no route can be drawn, no stage
     * marker placed and no distance computed. Requiring them at creation stops
     * that hole growing while the existing rows are backfilled.
     *
     * @param  array<string, mixed>  $stop
     * @return array{id: int, name: string, latitude: float|null, longitude: float|null}
     */
    private function resolveStop(array $stop, int $index): array
    {
        $position = $index + 1;

        if (! empty($stop['place_id'])) {
            $place = Place::find((int) $stop['place_id']);

            if ($place === null) {
                abort(422, "Stop {$position} refers to a place that does not exist.");
            }

            return [
                'id' => (int) $place->id,
                'name' => (string) $place->name,
                'latitude' => $place->latitude !== null ? (float) $place->latitude : null,
                'longitude' => $place->longitude !== null ? (float) $place->longitude : null,
            ];
        }

        $name = trim((string) ($stop['name'] ?? ''));

        if ($name === '') {
            abort(422, "Stop {$position} needs either an existing place_id or a name.");
        }

        if (! isset($stop['latitude'], $stop['longitude'])) {
            abort(422, "Stop {$position} (\"{$name}\") is new, so it needs a latitude and a longitude — drop a pin on the map.");
        }

        // Reuse an identically-named stop rather than minting another. `places`
        // is already 1,980 rows for 120 distinct names — 936 of them literally
        // called "Boarding Terminal not provided" — because every caller created
        // instead of matching. Two routes referencing two different rows for the
        // same real place never compare equal, so the segment search silently
        // fails to match them.
        $existing = Place::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

        if ($existing !== null) {
            // Backfill coordinates onto a row that has none — that is a strict
            // improvement — but never overwrite ones already recorded.
            if ($existing->latitude === null || $existing->longitude === null) {
                $existing->forceFill([
                    'latitude' => (float) $stop['latitude'],
                    'longitude' => (float) $stop['longitude'],
                ])->save();
            }

            return [
                'id' => (int) $existing->id,
                'name' => (string) $existing->name,
                'latitude' => (float) $existing->latitude,
                'longitude' => (float) $existing->longitude,
            ];
        }

        $place = Place::create([
            'name' => $name,
            'county_name' => $stop['county_name'] ?? null,
            'latitude' => (float) $stop['latitude'],
            'longitude' => (float) $stop['longitude'],
            'status' => true,
        ]);

        return [
            'id' => (int) $place->id,
            'name' => $place->name,
            'latitude' => (float) $place->latitude,
            'longitude' => (float) $place->longitude,
        ];
    }

    /**
     * Write the stages, with `distance` as CUMULATIVE kilometres from the origin
     * and `sequence` as the travel order.
     *
     * Both are stored because they answer different questions and can disagree:
     * `sequence` is the order the bus actually calls at them, `distance` is how
     * far along it is. A route that loops back on itself has a stop whose
     * straight-line distance from the origin falls, which would order it wrongly
     * — so segment search uses distance while drawing uses sequence.
     *
     * @param  array<int, array{id: int, name: string, latitude: float|null, longitude: float|null}>  $stops
     */
    private function writeStages(int $routeId, array $stops): void
    {
        $cumulative = 0.0;
        $previous = null;

        foreach ($stops as $index => $stop) {
            if ($previous !== null
                && $previous['latitude'] !== null && $previous['longitude'] !== null
                && $stop['latitude'] !== null && $stop['longitude'] !== null) {
                $cumulative += GeoDistance::km(
                    $previous['latitude'], $previous['longitude'],
                    $stop['latitude'], $stop['longitude']
                );
            } elseif ($previous !== null) {
                // An existing coordinate-less place is on this route. Distance
                // must still increase or the segment search would treat this
                // stop as being at the origin and offer the journey backwards.
                // One kilometre is a placeholder that preserves ORDER, which is
                // the only property that search depends on.
                $cumulative += 1.0;
            }

            RouteStage::create([
                'route_id' => $routeId,
                'place_id' => $stop['id'],
                'latitude' => $stop['latitude'],
                'longitude' => $stop['longitude'],
                'distance' => round($cumulative, 4),
                'sequence' => $index + 1,
                'status' => true,
            ]);

            $previous = $stop;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $stops
     * @return array<string, mixed>
     */
    private function present(Route $route, array $stops, float $fare): array
    {
        return [
            'id' => (int) $route->id,
            'sacco_id' => (int) $route->sacco_id,
            'name' => $route->name,
            'from' => $stops[0]['name'],
            'to' => $stops[count($stops) - 1]['name'],
            'fare' => $fare,
            'status' => (bool) $route->status,
            'stops' => array_map(static fn (array $s, int $i): array => [
                'sequence' => $i + 1,
                'place_id' => $s['id'],
                'name' => $s['name'],
                // Floats, not the strings PostgreSQL hands back for `double
                // precision` on models that declare no cast — a map should not
                // have to parseFloat its way around our column types.
                'latitude' => $s['latitude'],
                'longitude' => $s['longitude'],
            ], $stops, array_keys($stops)),
        ];
    }
}
