<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Dashboard\BookARide;

use App\Http\Controllers\Controller;
use App\Models\Route;
use App\Models\SaccoRoute;
use Illuminate\Http\JsonResponse;

/**
 * The handful of journeys to put on the passenger home screen.
 *
 * WHAT THIS REPLACES. The app shipped a `const` list compiled into the binary —
 * CBD to Syokimau, Kasarani and Karen. Checked against production on 2026-09-08,
 * Syokimau and Karen do not exist as PLACES at all, let alone on a route anybody
 * runs, and Kasarani is a place on no route. So the home screen advertised three
 * journeys a passenger could not book, and changing them needed a store release.
 *
 * RANKED BY QUEUES, because that is the only usage signal this platform has.
 * Bookings would be the natural measure and there are zero of them; queues are a
 * driver declaring "I am running this route now", which is at least a real event
 * a real person caused. It is a weak signal — the whole platform has 11 queues —
 * so the ordering is honest but shallow, and it will get sharper on its own as
 * queues accumulate. When bookings start flowing, rank on those instead.
 *
 * THE LIST IS TOPPED UP RATHER THAN LEFT SHORT. A route with no queue yet still
 * appears, after the ranked ones, so the screen is never emptier than the
 * catalogue. An empty home screen reads as a broken app; four routes of which
 * two are unproven reads as a small network, which is the truth.
 *
 * IT CARRIES PLACE IDS, not just names. book_a_ride/routes searches by
 * from_place_id/to_place_id, so a card without them is a dead end — the
 * passenger taps a journey and the app has nothing to search with. This is the
 * one field that makes the section a starting point rather than a poster.
 *
 * ONLY ROUTES A SACCO ACTUALLY RUNS, the same rule getRoutes applies for the
 * same reason: a route nobody operates cannot be booked, and offering it offers
 * a journey that cannot happen.
 *
 * NO FARE ON THE CARD. Several SACCOs run the same corridor at different
 * prices, so any single number here would be one operator's fare presented as
 * the route's. The fare belongs to the SACCO the passenger then picks, which
 * book_a_ride/route_saccos already answers.
 */
class PopularRoutesController extends Controller
{
    /** How far back a queue still counts as evidence the route is running. */
    private const WINDOW_DAYS = 30;

    /** How many the home screen shows. Fixed, not a parameter. */
    private const LIMIT = 4;

    /**
     * Popular routes
     *
     * The home screen's shortlist, busiest first. Always at most four.
     *
     * @authenticated
     */
    public function index(): JsonResponse
    {
        // Runnable = active, and adopted by a SACCO either directly or through
        // sacco_routes. Identical to the booking search, so a card can never
        // offer a journey that search would then refuse.
        $runnable = Route::query()
            ->where('routes.status', true)
            ->where(fn ($q) => $q
                ->whereNotNull('routes.sacco_id')
                ->orWhereIn('routes.id', SaccoRoute::withoutGlobalScopes()
                    ->where('status', true)->select('route_id')));

        $routes = $runnable
            ->with(['from:id,name', 'to:id,name'])
            ->withCount(['queues as trips' => fn ($q) => $q
                ->where('queues.created_at', '>=', now()->subDays(self::WINDOW_DAYS))])
            // Name last so a table full of ties does not reshuffle between
            // requests — a home screen that reorders itself on every refresh
            // looks broken even when the data is right.
            ->orderByDesc('trips')
            ->orderBy('routes.name')
            ->take(self::LIMIT)
            ->get();

        return response()->json([
            'routes' => $routes->map(fn (Route $r) => [
                'id' => $r->id,
                'name' => $r->name,
                // Place ids are the point — they are what book_a_ride/routes
                // takes as from_place_id / to_place_id.
                'from' => $r->from ? ['id' => $r->from->id, 'name' => $r->from->name] : null,
                'to' => $r->to ? ['id' => $r->to->id, 'name' => $r->to->name] : null,
                // Exposed so the client can style a proven route differently
                // from a listed one, rather than implying all four are busy.
                'trips' => (int) $r->trips,
            ])->values(),
        ]);
    }
}
