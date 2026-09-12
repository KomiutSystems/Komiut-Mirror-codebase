<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Passenger;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Place;
use App\Models\RouteStage;
use App\Models\VehicleLocation;
use App\Services\Booking\BookingState;
use App\Services\Location\VehicleLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where is my bus? -- everything the map screen for one booking needs, in one
 * call, then the socket takes over.
 *
 * The "My bookings" list shows a map button on every `trackable` row (live
 * booking, not yet boarded, trip not over). Tapping it opens Google Maps with
 * the bus, the pickup and the drop-off, and the route's stops as the line
 * between them. This endpoint is the first paint; every later movement of the
 * bus arrives on the private channel `trip.{queue_id}` as `vehicle.moved`,
 * which routes/channels.php already authorises for anyone who booked the queue.
 *
 * The bus position is the driver's phone. The crew app posts it every few
 * seconds while broadcasting; VehicleLocationService::FRESH_SECONDS is how
 * long a position stays believable. Past that the screen must say "last seen
 * N minutes ago", not draw a confident dot -- `bus.live` is that decision,
 * made here so no client has to know the constant.
 *
 * Ownership is the same rule as every other booking read: yours, or you hold
 * View Passengers. Nothing here is brand- or SACCO-scoped beyond that -- a
 * passenger has no SACCO, and the bus they booked is the bus they booked.
 */
final class BookingTrackingController extends Controller
{
    public function show(Request $request, int $id): JsonResponse
    {
        $booking = Booking::withoutGlobalScopes()
            ->with(['queue.queue_status', 'queue.vehicle:id,plate,fleet_no', 'queue.route:id,name,from_id,to_id', 'from:id,name,latitude,longitude', 'to:id,name,latitude,longitude'])
            ->find($id);

        if ($booking === null) {
            return response()->json(['error' => 'Invalid booking id'], 404);
        }

        $isStaff = $request->user()->can('View Passengers');
        if (! $isStaff && (int) $booking->user_id !== (int) $request->user()->id) {
            return response()->json(['error' => 'This booking is not yours.'], 403);
        }

        $queue = $booking->queue;
        $vehicle = $queue?->vehicle;
        $route = $queue?->route;

        $location = $vehicle === null ? null : VehicleLocation::withoutGlobalScopes()
            ->where('vehicle_id', $vehicle->id)
            ->first();

        return response()->json([
            'booking_id' => (int) $booking->id,
            'state' => BookingState::of($booking),
            'trackable' => BookingState::trackable($booking),
            'vehicle' => $vehicle === null ? null : [
                'id' => (int) $vehicle->id,
                'plate' => $vehicle->plate,
                'fleet_no' => $vehicle->fleet_no,
            ],
            'trip' => $queue === null ? null : [
                'queue_id' => (int) $queue->id,
                'status' => $queue->queue_status?->status,
                // Subscribe here for every move after this response.
                'channel' => 'trip.'.$queue->id,
                'event' => 'vehicle.moved',
            ],
            'bus' => $this->bus($location),
            'pickup' => $this->point(
                $booking->from,
                $booking->pickup_latitude !== null ? (float) $booking->pickup_latitude : null,
                $booking->pickup_longitude !== null ? (float) $booking->pickup_longitude : null,
                $route?->id,
            ),
            'dropoff' => $this->point($booking->to, null, null, $route?->id),
            'route' => $route === null ? null : [
                'id' => (int) $route->id,
                'name' => $route->name,
                'stops' => $this->stops((int) $route->id),
            ],
        ]);
    }

    /**
     * The bus's last known position and whether to believe it.
     *
     * @return array<string, mixed>|null
     */
    private function bus(?VehicleLocation $location): ?array
    {
        if ($location === null || $location->latitude === null || $location->longitude === null) {
            return null;
        }

        $age = $location->recorded_at === null ? null : max(0, (int) now()->diffInSeconds($location->recorded_at, true));
        $live = (bool) $location->broadcasting
            && $age !== null
            && $age <= VehicleLocationService::FRESH_SECONDS;

        return [
            'latitude' => (float) $location->latitude,
            'longitude' => (float) $location->longitude,
            'heading' => $location->heading === null ? null : (int) $location->heading,
            'recorded_at' => $location->recorded_at?->toIso8601String(),
            'age_seconds' => $age,
            'broadcasting' => (bool) $location->broadcasting,
            // Draw a confident dot only while this is true; otherwise say
            // "last seen N minutes ago" with the position greyed.
            'live' => $live,
        ];
    }

    /**
     * A stop as a map point. Prefers the exact roadside flag-down point a
     * pick-as-you-go booking recorded, then the route's own stage coordinates
     * for this place, then the place's coordinates.
     *
     * @return array<string, mixed>|null
     */
    private function point(?Place $place, ?float $exactLat, ?float $exactLng, ?int $routeId): ?array
    {
        if ($place === null) {
            return null;
        }

        $lat = $exactLat;
        $lng = $exactLng;

        if (($lat === null || $lng === null) && $routeId !== null) {
            $stage = RouteStage::where('route_id', $routeId)->where('place_id', $place->id)->first(['latitude', 'longitude']);
            if ($stage !== null && $stage->latitude !== null && $stage->longitude !== null) {
                $lat = (float) $stage->latitude;
                $lng = (float) $stage->longitude;
            }
        }

        if (($lat === null || $lng === null) && $place->latitude !== null && $place->longitude !== null) {
            $lat = (float) $place->latitude;
            $lng = (float) $place->longitude;
        }

        return [
            'place_id' => (int) $place->id,
            'name' => $place->name,
            'latitude' => $lat,
            'longitude' => $lng,
        ];
    }

    /**
     * The route's stops in order, for the polyline. Stops with no coordinates
     * are left out rather than drawn at (0,0).
     *
     * @return array<int, array<string, mixed>>
     */
    private function stops(int $routeId): array
    {
        return RouteStage::with('place:id,name')
            ->where('route_id', $routeId)
            ->orderBy('distance')
            ->get(['id', 'place_id', 'latitude', 'longitude', 'distance', 'sequence'])
            ->filter(fn (RouteStage $s) => $s->latitude !== null && $s->longitude !== null)
            ->values()
            ->map(fn (RouteStage $s) => [
                'place_id' => (int) $s->place_id,
                'name' => $s->place?->name,
                'latitude' => (float) $s->latitude,
                'longitude' => (float) $s->longitude,
                'sequence' => $s->sequence === null ? null : (int) $s->sequence,
            ])
            ->all();
    }
}
