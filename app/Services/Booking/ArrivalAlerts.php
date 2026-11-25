<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Enums\NotificationType;
use App\Models\Booking;
use App\Models\Place;
use App\Models\Queue;
use App\Models\RouteStage;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleFollower;
use App\Services\Geo\GeoDistance;
use App\Services\Notifications\NotificationService;
use App\Services\School\SchoolBuses;

/**
 * "Your bus is arriving": pushed to a paid passenger, once, the first time the
 * bus's live position comes within config('booking.arrival_alert_metres') of
 * their pickup point.
 *
 * Runs on every location ping of a live run (VehicleLocationController), so it
 * must cost nothing when there is nothing to do: one read on
 * (queue_id, status) for paid, unboarded, not-yet-alerted bookings, which is
 * empty on nearly every ping. The alert itself is a queued notification -- the
 * ping never waits on Firebase -- and the booking is claimed with a guarded
 * UPDATE first, so two pings in flight alert once.
 *
 * Sent from the server, not worked out on the passenger's phone, so it reaches
 * someone whose app is closed.
 *
 * A SCHOOL bus has no paid passengers -- it is never offered to them -- but
 * the parents following it get the same kind of push, "bus_approaching", once
 * per run, when it nears their stop. See followers(). A matatu pays nothing
 * for that: the cached SchoolBuses check answers without a query.
 */
final class ArrivalAlerts
{
    public function __construct(private readonly NotificationService $notifications) {}

    /** A bus carries a few dozen children; this bounds one ping's work whatever the table holds. */
    private const MAX_FOLLOWERS_PER_PING = 500;

    public function check(Queue $run, float $latitude, float $longitude): void
    {
        $radius = max(1, (int) config('booking.arrival_alert_metres', 200));

        if (SchoolBuses::isSchoolBus((int) $run->vehicle_id)) {
            $this->followers($run, $latitude, $longitude, $radius);
        }

        $waiting = Booking::withoutGlobalScopes()
            ->where('queue_id', $run->id)
            ->statusIs('confirmed')
            ->whereNull('arrival_alerted_at')
            ->with('from:id,name,latitude,longitude')
            ->get(['id', 'user_id', 'queue_id', 'from_id', 'pickup_latitude', 'pickup_longitude']);

        foreach ($waiting as $booking) {
            $pickup = $booking->from;
            $point = $this->pickupPoint($booking, $run);
            if ($pickup === null || $point === null) {
                continue;
            }

            $metres = GeoDistance::km($latitude, $longitude, $point[0], $point[1]) * 1000;
            if ($metres > $radius) {
                continue;
            }

            $claimed = Booking::withoutGlobalScopes()->whereKey($booking->id)
                ->whereNull('arrival_alerted_at')
                ->update(['arrival_alerted_at' => now()]);
            if ($claimed !== 1 || $booking->user_id === null) {
                continue;
            }

            $passenger = User::find($booking->user_id);
            if ($passenger === null) {
                continue;
            }

            $plate = optional($run->vehicle()->withoutGlobalScopes()->first(['id', 'plate']))->plate;
            $this->notifications->dispatch(
                $passenger,
                NotificationType::Trip,
                'Your bus is arriving',
                sprintf(
                    '%s is about %d m from %s. Get ready to board.',
                    $plate ?: 'Your bus',
                    max(50, (int) (round($metres / 50) * 50)),
                    $pickup->name ?: 'your pickup point',
                ),
                (string) $booking->id,
            );
        }
    }

    /**
     * "Your school bus is near": the parents following this bus whose stop it
     * is now within $radius of, once each per run.
     *
     * One read per ping, on vehicle_followers(vehicle_id), for followers whose
     * stop is near the bus and who have not yet been told about THIS run --
     * empty on almost every ping. The stop's place comes in the same
     * query, joined, so the distance needs no second read. Each parent is
     * claimed with a guarded UPDATE before the push, so two pings in flight
     * alert once; the next run has a new id and alerts again.
     *
     * The message names the bus and the distance, never a child: the follower
     * row holds none, by design (docs/school-bus-tracking.md rule 4).
     */
    private function followers(Queue $run, float $latitude, float $longitude, int $radius): void
    {
        $runId = (int) $run->id;

        // A box around the bus, a little wider than the radius, so the read
        // returns only stops that could be inside it: on most pings, none.
        // The exact distance is still measured below. A parent's own pin wins
        // over the place they picked, as in VehicleFollower::stopPoint().
        $dLat = ($radius * 1.1) / 111045.0;
        $dLon = $dLat / max(cos(deg2rad($latitude)), 1e-6);
        $lat = [$latitude - $dLat, $latitude + $dLat];
        $lng = [$longitude - $dLon, $longitude + $dLon];

        $waiting = VehicleFollower::query()
            ->leftJoin('places as stop_place', 'stop_place.id', '=', 'vehicle_followers.stop_place_id')
            ->where('vehicle_followers.vehicle_id', (int) $run->vehicle_id)
            ->where(fn ($q) => $q->whereNull('vehicle_followers.last_alerted_queue_id')
                ->orWhere('vehicle_followers.last_alerted_queue_id', '<>', $runId))
            ->where(fn ($q) => $q
                ->where(fn ($pin) => $pin->whereBetween('vehicle_followers.stop_latitude', $lat)
                    ->whereBetween('vehicle_followers.stop_longitude', $lng))
                ->orWhere(fn ($place) => $place
                    ->where(fn ($noPin) => $noPin->whereNull('vehicle_followers.stop_latitude')
                        ->orWhereNull('vehicle_followers.stop_longitude'))
                    ->whereBetween('stop_place.latitude', $lat)
                    ->whereBetween('stop_place.longitude', $lng)))
            ->orderBy('vehicle_followers.id')
            ->limit(self::MAX_FOLLOWERS_PER_PING)
            ->get([
                'vehicle_followers.id', 'vehicle_followers.user_id', 'vehicle_followers.stop_place_id',
                'vehicle_followers.stop_latitude', 'vehicle_followers.stop_longitude',
                'stop_place.latitude as stop_place_latitude', 'stop_place.longitude as stop_place_longitude',
            ]);

        $near = [];
        foreach ($waiting as $follower) {
            // Hand stopPoint() the place it would otherwise lazy-load, so the
            // rule for "where is this parent's stop" stays in one place.
            $follower->setRelation('stopPlace', $follower->stop_place_id === null ? null : (new Place)->forceFill([
                'id' => $follower->stop_place_id,
                'latitude' => $follower->stop_place_latitude,
                'longitude' => $follower->stop_place_longitude,
            ]));
            $point = $follower->stopPoint();
            if ($point === null) {
                continue;
            }

            $metres = GeoDistance::km($latitude, $longitude, $point[0], $point[1]) * 1000;
            if ($metres > $radius) {
                continue;
            }

            $claimed = VehicleFollower::query()->whereKey($follower->id)
                ->where(fn ($q) => $q->whereNull('last_alerted_queue_id')->orWhere('last_alerted_queue_id', '<>', $runId))
                ->update(['last_alerted_queue_id' => $runId, 'last_alerted_at' => now()]);
            if ($claimed === 1) {
                $near[(int) $follower->user_id] = $metres;
            }
        }

        if ($near === []) {
            return;
        }

        $plate = Vehicle::withoutGlobalScopes()->whereKey((int) $run->vehicle_id)->value('plate');
        // Parents are ordinary accounts in either brand: read by id, unscoped.
        $parents = User::withoutGlobalScopes()->whereIn('id', array_keys($near))->get();

        foreach ($parents as $parent) {
            // One parent's failed push must not cost the others theirs.
            try {
                $this->notifications->dispatch(
                    $parent,
                    NotificationType::BusApproaching,
                    'Your school bus is nearly there',
                    sprintf(
                        '%s is about %d m from your stop.',
                        $plate ? 'Your school bus '.$plate : 'Your school bus',
                        max(50, (int) (round($near[(int) $parent->id] / 50) * 50)),
                    ),
                    (string) $runId,
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Where the bus must stop for this passenger -- the same point their live
     * tracker shows (BookingTrackingController::point): the exact roadside
     * spot they booked from, else the route's stop, else the place. A roadside
     * booking is snapped to a stop up to a kilometre away, so measuring to the
     * stop alone would alert someone the bus had already passed.
     *
     * @return array{0: float, 1: float}|null
     */
    private function pickupPoint(Booking $booking, Queue $run): ?array
    {
        if ($booking->pickup_latitude !== null && $booking->pickup_longitude !== null) {
            return [(float) $booking->pickup_latitude, (float) $booking->pickup_longitude];
        }

        if ($run->route_id !== null) {
            $stage = RouteStage::where('route_id', $run->route_id)->where('place_id', $booking->from_id)->first(['latitude', 'longitude']);
            if ($stage !== null && $stage->latitude !== null && $stage->longitude !== null) {
                return [(float) $stage->latitude, (float) $stage->longitude];
            }
        }

        $place = $booking->from;

        return $place !== null && $place->latitude !== null && $place->longitude !== null
            ? [(float) $place->latitude, (float) $place->longitude]
            : null;
    }
}
