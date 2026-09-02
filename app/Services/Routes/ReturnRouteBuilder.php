<?php

declare(strict_types=1);

namespace App\Services\Routes;

use App\Models\Route;
use App\Models\RouteFare;
use App\Models\RouteStage;
use App\Models\SaccoRoute;
use Illuminate\Support\Facades\DB;

/**
 * The other direction. A matatu route is a there-and-back run, but the schema
 * stores one direction per row: `routes` has a single from_id/to_id, and both
 * queue writers require `route.from_id === terminus.place_id`. So a bus that
 * reaches Thika cannot queue for the trip home until Thika→CBD exists as its
 * own route.
 *
 * NICCO had three routes and no return legs, which made every far-end terminus
 * useless the moment it was created.
 *
 * WHAT IS MIRRORED. The stops in reverse, with distance re-measured from the new
 * origin; the SACCO's flat fare; and every stop-pair fare, swapped. A segment
 * costs what it costs whichever way you ride it — CBD→Ruiru at 50 means
 * Ruiru→CBD at 50 — and quoting nothing for the way home is worse than quoting
 * a price the SACCO can correct.
 *
 * WHAT IS NOT. Peak periods are per-SACCO, not per-route, so the returned fares
 * pick up the SACCO's existing windows automatically; nothing is copied.
 */
final class ReturnRouteBuilder
{
    /** The reverse of $route for this SACCO, creating it only if absent. */
    public function ensureFor(Route $route, int $saccoId, int $userId): ?Route
    {
        if ($route->from_id === null || $route->to_id === null || $route->from_id === $route->to_id) {
            return null;
        }

        return DB::transaction(function () use ($route, $saccoId, $userId): ?Route {
            $existing = Route::withoutGlobalScopes()
                ->where('sacco_id', $saccoId)
                ->where('from_id', $route->to_id)
                ->where('to_id', $route->from_id)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $stages = RouteStage::where('route_id', $route->id)
                ->orderBy('distance')->orderBy('id')
                ->get(['place_id', 'latitude', 'longitude', 'distance']);

            if ($stages->count() < 2) {
                return null;
            }

            $return = Route::create([
                'sacco_id' => $saccoId,
                'name' => $this->reverseName($route),
                'from_id' => $route->to_id,
                'to_id' => $route->from_id,
                'status' => 1,
            ]);

            // Distance is measured from the ORIGIN, so reversing the order means
            // subtracting each stop from the total rather than reusing the
            // outbound figure. Getting this wrong would order the stops
            // backwards and make the segment search offer the journey in
            // reverse.
            $total = (float) $stages->last()->distance;

            foreach ($stages->reverse()->values() as $index => $stage) {
                RouteStage::create([
                    'route_id' => $return->id,
                    'place_id' => $stage->place_id,
                    'latitude' => $stage->latitude,
                    'longitude' => $stage->longitude,
                    'distance' => round(max(0, $total - (float) $stage->distance), 4),
                    'sequence' => $index + 1,
                    'status' => true,
                ]);
            }

            $outbound = SaccoRoute::withoutGlobalScopes()
                ->where('sacco_id', $saccoId)->where('route_id', $route->id)->first();

            SaccoRoute::withoutGlobalScopes()->updateOrCreate(
                ['sacco_id' => $saccoId, 'route_id' => $return->id],
                [
                    'user_id' => $userId,
                    'amount' => (float) ($outbound->amount ?? 0),
                    'min_amount' => 0,
                    'status' => true,
                ],
            );

            $this->mirrorFares($route, $return, $saccoId);

            return $return;
        });
    }

    /** Every stop-pair fare, with pickup and dropoff swapped. */
    private function mirrorFares(Route $route, Route $return, int $saccoId): void
    {
        $fares = RouteFare::withoutGlobalScopes()
            ->where('sacco_id', $saccoId)
            ->where('route_id', $route->id)
            ->get();

        foreach ($fares as $fare) {
            RouteFare::withoutGlobalScopes()->updateOrCreate(
                [
                    'sacco_id' => $saccoId,
                    'route_id' => $return->id,
                    'from_place_id' => $fare->to_place_id,
                    'to_place_id' => $fare->from_place_id,
                    'fare_period_id' => $fare->fare_period_id,
                ],
                ['amount' => $fare->amount, 'status' => $fare->status],
            );
        }
    }

    /** "Nairobi CBD - Thika Main Stage" becomes "Thika Main Stage - Nairobi CBD". */
    private function reverseName(Route $route): string
    {
        $route->loadMissing(['from', 'to']);

        $from = $route->to?->name;
        $to = $route->from?->name;

        return $from !== null && $to !== null
            ? $from.' - '.$to
            : 'Return of '.($route->name ?? ('route '.$route->id));
    }
}
