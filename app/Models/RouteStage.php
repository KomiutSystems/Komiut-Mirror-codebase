<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RouteStage extends Model
{
    use HasFactory;

    protected $fillable = ['route_id', 'place_id', 'longitude', 'latitude', 'distance', 'sequence', 'status'];

    /** Next travel-order position for a route's stops (1-based). */
    public static function nextSequence(int $routeId): int
    {
        return (int) self::where('route_id', $routeId)->max('sequence') + 1;
    }

    /**
     * Cumulative kilometres for a stop appended to the end of a route, when its
     * real distance cannot be computed because a coordinate is missing.
     *
     * `distance` is NOT NULL and it is not decoration: book_a_ride/routes
     * decides whether a route serves a journey by testing
     * `pickup.distance < dropoff.distance`. So the only property that must hold
     * for the route to stay bookable is that distance INCREASES along it. One
     * kilometre past the current furthest stop preserves exactly that, and is
     * replaced by the real figure as soon as a pin is dropped.
     */
    public static function nextDistance(int $routeId): float
    {
        return round((float) self::where('route_id', $routeId)->max('distance') + 1.0, 4);
    }

    /**
     * Kilometres between a route's origin and a point, or null when either end
     * has no coordinates.
     *
     * Null is the CALLER's problem to handle — it must never reach the column.
     * This used to be an equirectangular approximation in statute miles
     * converted to km, one of three disagreeing distance formulas in the
     * codebase; it is now the same haversine the live map and the roadside
     * stop-snapping use.
     *
     * @param  object|null  $origin  Anything with latitude/longitude (a Place).
     * @param  object|null  $point   Likewise.
     */
    public static function distanceFrom(?object $origin, ?object $point): ?float
    {
        if ($origin === null || $point === null) {
            return null;
        }

        foreach ([$origin->latitude ?? null, $origin->longitude ?? null,
            $point->latitude ?? null, $point->longitude ?? null] as $coordinate) {
            if ($coordinate === null || $coordinate === '') {
                return null;
            }
        }

        return round(\App\Services\Geo\GeoDistance::km(
            (float) $origin->latitude,
            (float) $origin->longitude,
            (float) $point->latitude,
            (float) $point->longitude,
        ), 4);
    }

    public function place()
    {
        return $this->belongsTo(Place::class);
    }

    public function route()
    {
        return $this->belongsTo(Route::class);

    }
}
