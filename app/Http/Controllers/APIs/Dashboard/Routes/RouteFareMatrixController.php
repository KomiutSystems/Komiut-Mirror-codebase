<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Dashboard\Routes;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\FarePeriod;
use App\Models\Route;
use App\Models\RouteFare;
use App\Models\RouteStage;
use App\Models\Scopes\SaccoScope;
use App\Services\Fares\FareResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * @group Routes
 *
 * Every leg of a route and what each one costs — read and written as one grid.
 *
 * WHY THIS EXISTS. Fares are stored per stop-pair, and the only writer was
 * saccos/fares/add, which sets ONE pair per call. Pricing a route by hand
 * therefore cost a call per leg: 6 for a 4-stop route, 15 for 6 stops, 45 for
 * 10, times another full set per peak window. Nothing could read the grid back
 * either — getFares returns a flat 20-per-page list ordered by created_at, which
 * is the worst possible ordering for editing a table.
 *
 * So nobody priced anything. Every route on the platform had zero stop-pair
 * fares, which meant every leg fell through to the whole-route amount: measured
 * on NICCO's route 1973, Nairobi CBD to Ruiru and Nairobi CBD to Thika both
 * quoted 150/= — 21.96 km and 40.96 km at one price. A passenger riding 54% of
 * the route paid 100% of the fare.
 *
 * TWO PROPERTIES THIS IS BUILT AROUND.
 *
 * First, UNPRICED LEGS ARE PART OF THE ANSWER. The read returns every leg,
 * priced or not, because "which legs still fall back?" was the question nothing
 * could answer. An empty cell is the defect, not the absence of data.
 *
 * Second, THE WRITE IS ALL OR NOTHING. A half-priced route is more dangerous
 * than an unpriced one: it looks configured, and the gaps quietly overcharge.
 * One transaction, and a leg that fails validation takes the whole submission
 * with it.
 *
 * FORWARD LEGS ONLY. A route runs from its origin to its destination, and
 * `queues` are created against that direction — a passenger cannot board at
 * Thika and alight at the CBD on a CBD→Thika trip. Pairs are therefore emitted
 * in stop order only, which halves the grid and removes the reverse-direction
 * cells that would otherwise sit there looking editable and never be read.
 */
class RouteFareMatrixController extends Controller
{
    use ResolvesTenant;

    public function __construct(private readonly FareResolver $fares)
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * The fare grid for one route
     *
     * Every forward leg, with its base price, its price in each peak window, and
     * whether it is priced at all.
     *
     * @authenticated
     *
     * @response 404 {"error": "That route is not yours."}
     */
    public function show(Request $request, int $routeId): JsonResponse
    {
        $saccoId = $this->resolveSaccoId($request);

        if ($saccoId === null) {
            return $this->foreignSaccoDenied();
        }

        // Route::find is SACCO-scoped, so another operator's route is simply not
        // there — the 404 is the tenant boundary, not a lookup failure.
        $route = Route::query()->whereKey($routeId)->first();

        if ($route === null) {
            return response()->json(['error' => 'That route is not yours.'], 404);
        }

        $stops = $this->stopsOf($routeId);

        if (count($stops) < 2) {
            return response()->json([
                'error' => 'This route needs at least two stops before it can be priced.',
            ], 422);
        }

        $periods = FarePeriod::withoutGlobalScope(SaccoScope::class)
            ->where('sacco_id', $saccoId)
            ->where('status', true)
            ->orderByDesc('priority')->orderBy('id')
            ->get(['id', 'name', 'days', 'start_time', 'end_time', 'priority']);

        $priced = $this->existingFares($saccoId, $routeId);

        $legs = [];
        $unpriced = 0;

        foreach ($stops as $i => $from) {
            foreach (array_slice($stops, $i + 1) as $to) {
                $key = $from['place_id'].':'.$to['place_id'];
                $base = $priced['base'][$key] ?? null;

                if ($base === null) {
                    $unpriced++;
                }

                $legs[] = [
                    'from_id' => $from['place_id'],
                    'from' => $from['name'],
                    'to_id' => $to['place_id'],
                    'to' => $to['name'],
                    // The distance the platform already holds for these stops,
                    // so a SACCO pricing a grid can see which legs are long.
                    'km' => $to['distance'] !== null && $from['distance'] !== null
                        ? round((float) $to['distance'] - (float) $from['distance'], 2)
                        : null,
                    'base' => $base,
                    // Keyed by period id, absent where that window has no price
                    // of its own and the base applies.
                    'peak' => $priced['peak'][$key] ?? new \stdClass(),
                    'is_priced' => $base !== null,
                ];
            }
        }

        return response()->json([
            'route' => [
                'id' => (int) $route->id,
                'name' => $route->name,
                'sacco_id' => $saccoId,
            ],
            'stops' => $stops,
            // The whole-route price. This is what an unpriced leg currently
            // falls back to, which is why it belongs on the same screen.
            'flat' => $this->fares->bundle($saccoId, $routeId)['flat'],
            'periods' => $periods,
            'legs' => $legs,
            // The headline number: how much of this route is still guessing.
            'unpriced_legs' => $unpriced,
            'total_legs' => count($legs),
        ]);
    }

    /**
     * Save the fare grid for one route
     *
     * The whole grid in one transactional call. A leg with a null amount has its
     * price REMOVED and returns to falling back on the whole-route fare.
     *
     * @authenticated
     *
     * @bodyParam legs array required Each: from_id, to_id, amount (nullable), fare_period_id (nullable).
     *
     * @response 422 {"errors": {"legs.0.to_id": ["Ruiru comes before Nairobi CBD on this route."]}}
     */
    public function store(Request $request, int $routeId): JsonResponse
    {
        $saccoId = $this->resolveSaccoId($request);

        if ($saccoId === null) {
            return $this->foreignSaccoDenied();
        }

        if (! auth()->user()->can('Edit Routes') && ! auth()->user()->can('Add Routes')) {
            return response()->json(['error' => 'You do not have permission to price routes.'], 403);
        }

        $route = Route::query()->whereKey($routeId)->first();

        if ($route === null) {
            return response()->json(['error' => 'That route is not yours.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'legs' => 'required|array|min:1',
            'legs.*.from_id' => 'required|integer|min:1',
            'legs.*.to_id' => 'required|integer|min:1|different:legs.*.from_id',
            'legs.*.amount' => 'nullable|numeric|min:0',
            'legs.*.fare_period_id' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $stops = collect($this->stopsOf($routeId));
        $order = $stops->pluck('sequence', 'place_id');
        $names = $stops->pluck('name', 'place_id');

        // Every period named anywhere in the payload must belong to this SACCO.
        // Checked up front and once, rather than per leg inside the loop.
        $periodIds = collect($request->input('legs'))
            ->pluck('fare_period_id')->filter()->unique()->map(fn ($id) => (int) $id);

        if ($periodIds->isNotEmpty()) {
            $mine = FarePeriod::withoutGlobalScope(SaccoScope::class)
                ->where('sacco_id', $saccoId)->whereIn('id', $periodIds)->pluck('id');

            if ($mine->count() !== $periodIds->count()) {
                // Ownership, not existence: pricing against another SACCO's
                // period would make this SACCO's fares move whenever they
                // edited their rush hour.
                return response()->json(['error' => 'A fare period in this grid does not belong to your SACCO.'], 403);
            }
        }

        $errors = [];

        foreach ((array) $request->input('legs') as $i => $leg) {
            $from = (int) ($leg['from_id'] ?? 0);
            $to = (int) ($leg['to_id'] ?? 0);

            if (! $order->has($from)) {
                $errors["legs.{$i}.from_id"] = ['That stop is not on this route.'];

                continue;
            }

            if (! $order->has($to)) {
                $errors["legs.{$i}.to_id"] = ['That stop is not on this route.'];

                continue;
            }

            // TRAVEL ORDER. addFare never checked this, so a reversed pair could
            // be saved and would sit in the admin listing looking like the route
            // was priced — while being unreachable by any booking, because
            // nobody rides Thika to the CBD on a CBD-to-Thika trip. A fare that
            // can never be charged is worse than a missing one: it hides the gap.
            if ($order[$from] >= $order[$to]) {
                $errors["legs.{$i}.to_id"] = [
                    ($names[$to] ?? 'That stop').' comes before '.($names[$from] ?? 'the pickup').' on this route.',
                ];
            }
        }

        if ($errors !== []) {
            return response()->json(['errors' => $errors], 422);
        }

        $saved = 0;
        $cleared = 0;

        DB::transaction(function () use ($request, $saccoId, $routeId, &$saved, &$cleared): void {
            foreach ((array) $request->input('legs') as $leg) {
                $key = [
                    'sacco_id' => $saccoId,
                    'route_id' => $routeId,
                    'from_place_id' => (int) $leg['from_id'],
                    'to_place_id' => (int) $leg['to_id'],
                    // Part of the KEY: the base fare and each period's fare are
                    // separate rows for one segment, which is what the partial
                    // unique indexes enforce.
                    'fare_period_id' => isset($leg['fare_period_id']) && $leg['fare_period_id']
                        ? (int) $leg['fare_period_id']
                        : null,
                ];

                // A null amount CLEARS the leg rather than storing zero. Zero is
                // a free ride, which is a real thing a SACCO might mean; "no
                // price set" is a different thing entirely, and the two must not
                // collapse into each other.
                if (! isset($leg['amount']) || $leg['amount'] === null || $leg['amount'] === '') {
                    $cleared += RouteFare::withoutGlobalScope(SaccoScope::class)->where($key)->delete();

                    continue;
                }

                RouteFare::withoutGlobalScope(SaccoScope::class)->updateOrCreate($key, [
                    'amount' => (float) $leg['amount'],
                    'status' => true,
                ]);

                $saved++;
            }
        });

        $this->fares->forget($saccoId, $routeId);

        return response()->json([
            'success' => 'Fares saved.',
            'priced' => $saved,
            'cleared' => $cleared,
        ]);
    }

    /**
     * This route's stops, in travel order.
     *
     * @return list<array{place_id:int, name:string, sequence:int, distance:float|null}>
     */
    private function stopsOf(int $routeId): array
    {
        return RouteStage::withoutGlobalScopes()
            ->join('places', 'places.id', '=', 'route_stages.place_id')
            // QUALIFIED, both of them. `route_stages` and `places` each carry a
            // `status`, so an unqualified one is ambiguous the moment the join
            // is added — PostgreSQL rejects the whole statement, and because the
            // request is inside a transaction the real error is then masked by
            // whatever runs next.
            ->where('route_stages.route_id', $routeId)
            ->where('route_stages.status', true)
            // sequence is the authored order; distance breaks ties for the
            // legacy rows written before sequence existed.
            ->orderBy('route_stages.sequence')
            ->orderBy('route_stages.distance')
            ->get([
                'route_stages.place_id',
                'places.name',
                'route_stages.sequence',
                'route_stages.distance',
            ])
            ->map(fn ($r) => [
                'place_id' => (int) $r->place_id,
                'name' => (string) $r->name,
                'sequence' => (int) $r->sequence,
                'distance' => $r->distance !== null ? (float) $r->distance : null,
            ])
            ->values()
            ->all();
    }

    /**
     * What is priced today, split into base and per-period.
     *
     * @return array{base: array<string, float>, peak: array<string, array<int, float>>}
     */
    private function existingFares(int $saccoId, int $routeId): array
    {
        $rows = RouteFare::withoutGlobalScope(SaccoScope::class)
            ->where('sacco_id', $saccoId)
            ->where('route_id', $routeId)
            ->where('status', true)
            ->get(['from_place_id', 'to_place_id', 'amount', 'fare_period_id']);

        $base = [];
        $peak = [];

        foreach ($rows as $row) {
            $key = $row->from_place_id.':'.$row->to_place_id;

            if ($row->fare_period_id === null) {
                $base[$key] = (float) $row->amount;

                continue;
            }

            $peak[$key][(int) $row->fare_period_id] = (float) $row->amount;
        }

        return ['base' => $base, 'peak' => $peak];
    }
}
