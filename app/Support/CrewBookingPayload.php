<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Resources\DriverBookingResource;
use App\Models\Booking;
use App\Models\Queue;
use App\Services\Loyalty\BookingSettlement;

/**
 * One booking, in the shape the crew's screen already renders.
 *
 * DELIBERATELY IDENTICAL to a row from DriverQueueController::bookings -- a
 * DriverBookingResource plus `status_label` -- so the app parses a pushed
 * booking and a fetched one through the same code, exactly as PaymentRecorded
 * matches recentTransactions.
 *
 * The relations are loaded HERE because a queued broadcast restores the model
 * from its id and carries none: `whenLoaded('from')` would then resolve to
 * MissingValue, pickup and dropoff would arrive null, and every pushed row
 * would read "Pickup -> Dropoff" with no stop names on it.
 */
final class CrewBookingPayload
{
    /** @return array<string, mixed> */
    public static function for(Booking $booking): array
    {
        $booking->loadMissing(['from', 'to', 'seats']);

        // Without this a points fare pushes as PAID with a KES figure and no
        // way for the conductor to tell it collected nothing at the door.
        BookingSettlement::annotateOne($booking);

        return array_merge(
            (new DriverBookingResource($booking))->toArray(request()),
            ['status_label' => $booking->status_label],
        );
    }

    /**
     * The bus this booking is on, or null when it hangs off no queue.
     *
     * Read unscoped and by id: a broadcast runs in a queue worker with no
     * authenticated user, so a tenant scope on the relation would resolve the
     * queue to null and silently drop the event.
     */
    public static function vehicleId(Booking $booking): ?int
    {
        if ($booking->queue_id === null) {
            return null;
        }

        $id = Queue::withoutGlobalScopes()->whereKey($booking->queue_id)->value('vehicle_id');

        return $id === null ? null : (int) $id;
    }
}
