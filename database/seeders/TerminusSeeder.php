<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Place;
use App\Models\Route;
use App\Models\Terminus;
use Illuminate\Database\Seeder;

/**
 * The stages a matatu queues at.
 *
 * `termini` was empty, so queues/join rejected every attempt — and because a
 * trip starts from a queue, and a location broadcast needs a queue, that one
 * empty table closed the entire driver shift workflow.
 *
 * TERMINI ARE DERIVED FROM ROUTE ORIGINS, not from a hand-written list.
 * queues/join checks that the terminus is the START of the route being joined:
 *
 *     "Terminus is not the start of this route."
 *
 * so a terminus at a place no route departs from is unusable — which is exactly
 * what a hand-written list produced. Seeding from `routes.from_id` guarantees
 * every terminus is joinable by at least one route.
 *
 * A terminus needs only a Place, never a route of its own, so this fills the
 * gap without touching the unresolved question of whether the seeded Nairobi
 * routes should be replaced by legacy's.
 *
 * Idempotent on place: re-running after a deploy adds only genuinely new
 * origins and never duplicates.
 */
class TerminusSeeder extends Seeder
{
    /**
     * Origins to turn into termini.
     *
     * Bounded rather than every distinct origin: the seeded route set has ~2,000
     * routes and most origins are mid-route stops that nobody queues at. The
     * busiest origins are the real stages.
     */
    private const MAX_TERMINI = 40;

    public function run(): void
    {
        $origins = Route::query()
            ->whereNotNull('from_id')
            ->selectRaw('from_id, COUNT(*) as routes')
            ->groupBy('from_id')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(self::MAX_TERMINI)
            ->pluck('from_id');

        foreach ($origins as $placeId) {
            $place = Place::find($placeId);
            if ($place === null) {
                continue;
            }

            Terminus::firstOrCreate(
                // Keyed on the place, not the name: two places can share a name,
                // and it is the place that makes a terminus joinable.
                ['place_id' => $place->id],
                [
                    'name' => $place->name,
                    'longitude' => $place->longitude,
                    'latitude' => $place->latitude,
                    'status' => true,
                ],
            );
        }
    }
}
