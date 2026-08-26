<?php

declare(strict_types=1);

namespace App\Services\Geo;

/**
 * Great-circle distance, in kilometres.
 *
 * ONE implementation. There were three, and they did not agree:
 * VehicleLocationService and BroadcastReservationController each carried a
 * private haversine on R = 6371.0088 km (the second copied because the first was
 * private), and RouteAPIController used an equirectangular approximation in
 * STATUTE MILES converted to km to populate route_stages.distance. So "how far
 * along the route is this stop" and "how far is this bus from me" were answering
 * with different maths, and the one that ordered stops was the least accurate of
 * the three.
 *
 * That matters more than the metres: route_stages.distance is not decoration.
 * book_a_ride/routes decides whether a route serves a passenger's journey by
 * testing `pickup.distance < dropoff.distance`, so this number is what makes a
 * route findable at all, and what stops it being offered backwards.
 */
final class GeoDistance
{
    /** IUGG mean Earth radius, km. Matches the two existing haversines. */
    public const EARTH_RADIUS_KM = 6371.0088;

    /**
     * Kilometres between two decimal-degree points.
     *
     * Haversine rather than the equirectangular approximation: on a matatu route
     * the difference is small, but it costs nothing here and removes a class of
     * "why is this stop ordered before that one" question near the equator.
     */
    public static function km(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt($a)));
    }
}
