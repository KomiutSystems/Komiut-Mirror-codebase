<?php

declare(strict_types=1);

namespace App\Services\Location;

use App\Events\VehicleMoved;
use App\Models\Booking;
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

    /**
     * $routeId is the route a driver says they are running when no queue is
     * carrying one. Going live and being on a trip are independent -- a bus can
     * broadcast while waiting at the stage, or run a trip with the app closed --
     * so the route cannot come from the queue alone or a live-but-unqueued bus
     * would be invisible to every route-filtered search.
     */
    public function update(int $vehicleId, float $latitude, float $longitude, ?Queue $queue = null, ?int $routeId = null): VehicleLocation
    {
        $previous = VehicleLocation::where('vehicle_id', $vehicleId)->first();

        $heading = $previous !== null
            ? $this->bearing($previous->latitude, $previous->longitude, $latitude, $longitude)
            : 0;

        $location = VehicleLocation::updateOrCreate(
            ['vehicle_id' => $vehicleId],
            [
                'route_id' => $queue?->route_id ?? $routeId,
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

        // Seats held per trip, in ONE query for the whole result set. Doing it
        // per vehicle would be a round trip each, and this endpoint is polled by
        // every passenger with the map open.
        $seatsTaken = $this->seatsTakenByQueue();

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
                    // How many of those are still free.
                    //
                    // NULL, not the capacity, when the bus is broadcasting with
                    // no trip open: we genuinely do not know, and answering with
                    // the full capacity would promise a passenger seats nobody
                    // has counted. A number that might be wrong is worse than an
                    // absent one on a screen someone uses to decide whether to
                    // wait at the stage.
                    'seats_available' => $this->seatsAvailable($loc, $seatsTaken),
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

    /**
     * Seats still free on this bus, or null when there is no trip to count
     * against.
     *
     * Whole-trip rather than segment-aware, and deliberately conservative. A
     * seat freed halfway along the route is counted as taken here, so the number
     * can understate availability but never overstate it — a passenger told
     * "2 seats" and finding none has been lied to, while one told "0" and
     * finding a seat has only been surprised.
     *
     * SegmentSeatAvailability remains the authority at the moment of booking,
     * where the pickup and dropoff are known and a seat can honestly be reused.
     */
    private function seatsAvailable(VehicleLocation $loc, Collection $seatsTaken): ?int
    {
        $capacity = $loc->vehicle?->seat?->seats;

        if ($capacity === null || $loc->queue_id === null) {
            return null;
        }

        return max(0, (int) $capacity - (int) $seatsTaken->get((int) $loc->queue_id, 0));
    }

    /**
     * queue_id => seats currently held, across every live trip.
     *
     * Counts the same bookings SegmentSeatAvailability does: live rows that are
     * either paid or still inside the unpaid-hold window. A passenger part-way
     * through paying holds their seat, or two people would be sold the same one
     * — and a conductor's cash fare counts exactly like an app fare, because the
     * seat is occupied either way and the money is reconciled to the till later.
     *
     * @return Collection<int, int>
     */
    private function seatsTakenByQueue(): Collection
    {
        $cutoff = now()->subMinutes((int) config('booking.hold_minutes', 10));

        return Booking::withoutGlobalScopes()
            ->whereNotNull('queue_id')
            ->where('status', true)
            ->where(fn ($q) => $q->where('paid', true)->orWhere('created_at', '>=', $cutoff))
            ->selectRaw('queue_id, COUNT(*) as held')
            ->groupBy('queue_id')
            ->pluck('held', 'queue_id');
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
