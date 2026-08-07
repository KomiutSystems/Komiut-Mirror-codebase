<?php

declare(strict_types=1);

namespace App\Services\Location;

use App\Events\VehicleMoved;
use App\Models\Queue;
use App\Models\Vehicle;
use App\Models\VehicleLocation;
use Illuminate\Support\Collection;

/**
 * Records driver pings and answers "which vehicles are near me / on my route".
 *
 * Every ping upserts the vehicle's row and broadcasts VehicleMoved over Reverb —
 * that is the realtime channel. `nearby()` is a discovery query (bounding box in
 * SQL, exact haversine in PHP) that stays portable across sqlite/pg and needs no
 * Redis at matatu-SACCO scale.
 */
final class VehicleLocationService
{
    private const EARTH_KM = 6371.0088;

    /** A ping older than this is stale and never surfaced as "live". */
    public const FRESH_SECONDS = 120;

    public function update(int $vehicleId, float $latitude, float $longitude, ?Queue $queue = null): VehicleLocation
    {
        $previous = VehicleLocation::where('vehicle_id', $vehicleId)->first();

        $heading = $previous !== null
            ? $this->bearing($previous->latitude, $previous->longitude, $latitude, $longitude)
            : 0;

        $location = VehicleLocation::updateOrCreate(
            ['vehicle_id' => $vehicleId],
            [
                'route_id' => $queue?->route_id,
                'queue_id' => $queue?->id,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'heading' => $heading,
                'broadcasting' => true,
                'recorded_at' => now(),
            ],
        );

        $plate = Vehicle::withoutGlobalScopes()->whereKey($vehicleId)->value('plate');
        VehicleMoved::dispatch($location, $plate);

        return $location;
    }

    /** Driver ended the trip — stop appearing on the live map. */
    public function stop(int $vehicleId): void
    {
        VehicleLocation::where('vehicle_id', $vehicleId)->update(['broadcasting' => false]);
    }

    /**
     * Live vehicles within radiusKm of a point, optionally constrained to a
     * route, freshest first. Each entry carries the plate, capacity and distance.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function nearby(float $latitude, float $longitude, float $radiusKm = 5.0, ?int $routeId = null): Collection
    {
        $dLat = $radiusKm / 111.045;
        $cos = cos(deg2rad($latitude)) ?: 1e-6;
        $dLon = $radiusKm / (111.045 * $cos);

        $candidates = VehicleLocation::query()
            ->with(['vehicle.seat', 'vehicle.sacco', 'route'])
            ->where('broadcasting', true)
            ->where('recorded_at', '>=', now()->subSeconds(self::FRESH_SECONDS))
            ->whereBetween('latitude', [$latitude - $dLat, $latitude + $dLat])
            ->whereBetween('longitude', [$longitude - $dLon, $longitude + $dLon])
            ->when($routeId !== null, fn ($q) => $q->where('route_id', $routeId))
            ->get();

        return $candidates
            ->map(function (VehicleLocation $loc) use ($latitude, $longitude, $radiusKm) {
                $distance = $this->haversine($latitude, $longitude, $loc->latitude, $loc->longitude);
                if ($distance > $radiusKm) {
                    return null;
                }

                return [
                    'vehicle_id' => $loc->vehicle_id,
                    'plate' => $loc->vehicle?->plate,
                    'capacity' => $loc->vehicle?->seat?->seats,
                    'sacco' => $loc->vehicle?->sacco?->name,
                    'route_id' => $loc->route_id,
                    // The payload already denormalises plate and sacco NAME; a
                    // bare route_id was the odd one out. The passenger's
                    // "matatus approaching" screen has no cheap way to resolve
                    // an id to a name, so it rendered the route blank.
                    'route_name' => $loc->route?->name,
                    'queue_id' => $loc->queue_id,
                    'latitude' => $loc->latitude,
                    'longitude' => $loc->longitude,
                    'heading' => $loc->heading,
                    'distance_km' => round($distance, 3),
                    'recorded_at' => optional($loc->recorded_at)->toIso8601String(),
                ];
            })
            ->filter()
            ->sortBy('distance_km')
            ->values();
    }

    /** Great-circle distance in km. */
    private function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return self::EARTH_KM * 2 * asin(min(1.0, sqrt($a)));
    }

    /** Compass bearing 0–359° from point 1 to point 2. */
    private function bearing(float $lat1, float $lon1, float $lat2, float $lon2): int
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dLon = deg2rad($lon2 - $lon1);

        $y = sin($dLon) * cos($phi2);
        $x = cos($phi1) * sin($phi2) - sin($phi1) * cos($phi2) * cos($dLon);

        return (int) round(fmod(rad2deg(atan2($y, $x)) + 360, 360));
    }
}
