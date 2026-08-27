<?php

declare(strict_types=1);

namespace App\Services\Routes;

use App\Models\Place;
use App\Models\SaccoTerminus;
use App\Models\Terminus;

/**
 * The main stage a route departs from, and a SACCO's right to work out of it.
 *
 * WHY THIS HAS TO EXIST. `queues` requires a terminus whose place IS the route's
 * origin — both the driver and the dispatcher enforce that, and terminus_id is
 * NOT NULL, so a route without one fails as a 422 and never degrades into
 * something half-working. A booking needs a queue. So "no terminus" means the
 * route is invisible to every passenger, permanently and silently.
 *
 * Production had exactly that: two SACCO-owned routes, neither with a terminus
 * anywhere near its origin, and `sacco_termini` holding ZERO rows across all 48
 * SACCOs after three years — because the only writer was a superadmin-only
 * console, so a SACCO admin building their own route could not attach one.
 *
 * TWO ROWS, because the schema splits the stage from the operators using it.
 * `termini` is the physical kerb; `sacco_termini` is who works out of it.
 *
 * SHARED BY PLACE, deliberately. A terminus belongs to a PLACE, not to a route
 * and not to a SACCO — one stage, used by everyone who stops there. So a second
 * SACCO starting at the same kerb is linked to the existing row rather than
 * having a duplicate invented for it. Inventing duplicates is how you end up
 * with 41 terminus rows that mean about 20 real stages. It is also why creating
 * one here is safe while EDITING one is a platform action.
 */
final class RouteTerminusProvisioner
{
    /**
     * Ensure a terminus exists at this place and that this SACCO may depart
     * from it. Returns null only when the place itself is missing.
     *
     * $userId is REQUIRED, not optional, because `sacco_termini.user_id` is NOT
     * NULL — it records who granted the SACCO its right to work out of the
     * stage, matching the sibling sacco_routes pivot. A nullable parameter here
     * would compile, pass review, and then fail on the first insert.
     */
    public function ensureFor(int $placeId, int $saccoId, int $userId): ?Terminus
    {
        $place = Place::withoutGlobalScopes()->find($placeId);

        if ($place === null) {
            return null;
        }

        $terminus = Terminus::withoutGlobalScopes()
            ->where('place_id', $placeId)
            ->first();

        if ($terminus === null) {
            $terminus = Terminus::create([
                'name' => (string) $place->name,
                'place_id' => $placeId,
                // Carried from the place so the driver's geofence has something
                // to measure against. A terminus with no coordinates cannot
                // check whether anyone is actually standing at it.
                'longitude' => $place->longitude,
                'latitude' => $place->latitude,
                'status' => true,
            ]);
        }

        SaccoTerminus::withoutGlobalScopes()->updateOrCreate(
            ['sacco_id' => $saccoId, 'terminus_id' => $terminus->id],
            ['user_id' => $userId]
        );

        return $terminus;
    }
}
