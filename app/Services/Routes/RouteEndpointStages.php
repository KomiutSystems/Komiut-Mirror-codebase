<?php

declare(strict_types=1);

namespace App\Services\Routes;

use App\Models\Place;
use App\Models\Route;
use App\Models\RouteStage;
use App\Services\Geo\GeoDistance;
use Illuminate\Support\Facades\DB;

/**
 * A route's own first and last stop must exist in `route_stages`.
 *
 * `routes.from_id`/`to_id` name the endpoints, but RouteAPIController@addRoute
 * only ever set those columns — it never wrote stage rows for them. Nothing
 * reads the columns when deciding whether a route can serve a journey:
 * book_a_ride/routes and book_a_ride/queues both join `route_stages` twice and
 * require `pickup.distance < dropoff.distance`, so a route whose endpoints are
 * not stages can never match ANY pickup→dropoff pair. It is invisible to the
 * app while looking perfectly healthy on the dashboard.
 *
 * Prod route 1972 is exactly that: from Ambassadeur to place 1790, one stage
 * (place 1881), neither endpoint among them, and unbookable since the day it
 * was created.
 *
 * Distance, not sequence, is the load-bearing column — it is what the segment
 * search compares — so this repairs both and leaves them agreeing, which they
 * previously did not: addRouteStage appends `sequence` in save order while
 * computing `distance` geographically.
 */
class RouteEndpointStages
{
    /**
     * Ensure both endpoints are stages, then renumber the route in travel order.
     *
     * @return int stages added (0 when the route was already whole)
     */
    public function ensure(Route $route): int
    {
        if ($route->from_id === null || $route->to_id === null || $route->from_id === $route->to_id) {
            return 0;
        }

        return DB::transaction(function () use ($route): int {
            $existing = RouteStage::where('route_id', $route->id)
                ->pluck('place_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $added = 0;

            // The origin anchors the route at zero; every other distance is
            // measured from it, which is what addRouteStage already assumes.
            if (! in_array((int) $route->from_id, $existing, true)) {
                $this->add($route, (int) $route->from_id, 0.0);
                $added++;
            }

            if (! in_array((int) $route->to_id, $existing, true)) {
                $this->add($route, (int) $route->to_id, $this->farEnd($route));
                $added++;
            }

            if ($added > 0) {
                $this->renumber((int) $route->id);
            }

            return $added;
        });
    }

    private function add(Route $route, int $placeId, float $distance): void
    {
        $place = Place::withoutGlobalScopes()->find($placeId);

        RouteStage::create([
            'route_id' => $route->id,
            'place_id' => $placeId,
            'longitude' => $place?->longitude,
            'latitude' => $place?->latitude,
            'distance' => round($distance, 4),
            'sequence' => RouteStage::nextSequence((int) $route->id),
            'status' => true,
        ]);
    }

    /**
     * How far the destination sits from the origin.
     *
     * Real kilometres when both places carry coordinates. Otherwise one past the
     * furthest existing stop — the destination only has to sort LAST for the
     * segment search to work, and a wrong number that preserves order is far
     * better than a zero that puts the end of the route at its beginning.
     */
    private function farEnd(Route $route): float
    {
        $from = Place::withoutGlobalScopes()->find($route->from_id);
        $to = Place::withoutGlobalScopes()->find($route->to_id);

        if ($from?->latitude !== null && $from?->longitude !== null
            && $to?->latitude !== null && $to?->longitude !== null) {
            $km = GeoDistance::km(
                (float) $from->latitude, (float) $from->longitude,
                (float) $to->latitude, (float) $to->longitude,
            );

            if ($km > 0) {
                return $km;
            }
        }

        return (float) RouteStage::where('route_id', $route->id)->max('distance') + 1.0;
    }

    /** Number the stops 1..n in travel order, so `sequence` agrees with `distance`. */
    private function renumber(int $routeId): void
    {
        $ids = RouteStage::where('route_id', $routeId)
            ->orderBy('distance')->orderBy('id')
            ->pluck('id');

        foreach ($ids as $position => $id) {
            RouteStage::whereKey($id)->update(['sequence' => $position + 1]);
        }
    }
}
