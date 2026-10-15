<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Enums\NotificationType;
use App\Models\Booking;
use App\Models\Queue;
use App\Models\RouteStage;
use App\Models\User;
use App\Services\Geo\GeoDistance;
use App\Services\Notifications\NotificationService;

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
 */
final class ArrivalAlerts
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function check(Queue $run, float $latitude, float $longitude): void
    {
        $radius = max(1, (int) config('booking.arrival_alert_metres', 200));

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
