<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Enums\BookingCancellationReason;
use App\Models\Booking;
use Illuminate\Support\Collection;

/**
 * Where a booking is in its life, in the words a passenger's screen uses.
 *
 * BookingStatus names four states from three flags and is what the crew app
 * and the dashboard filter on. It is not enough for the passenger, for whom
 * "failed" is three different things -- a hold that expired unpaid, a booking
 * they cancelled, and a paid seat the crew marked not boarded and refunded --
 * and for whom "boarded" continues past the end of the trip. This adds the
 * cancellation reason (persisted since 2026-09-12) and the trip's own status:
 *
 *   reserved      booked, not paid. Pay, or the unpaid sweep expires it.
 *   confirmed     paid, waiting for the bus.            <- the map button
 *   boarded       the crew tapped board; you are on the bus.
 *   completed     boarded, and the trip has ended.
 *   not_boarded   the crew marked you not boarded, or the trip ended without
 *                 you and the crew said so. What you paid came back as points
 *                 (the points themselves if you paid points).
 *   cancelled     cancelled deliberately -- by you, or by the office.
 *   expired       the unpaid hold ran out.
 *
 * `trackable` is the one question the "My bookings" list asks of every row:
 * is there a bus to show on a map for this booking? Yes while the booking is
 * live and you are not yet on it -- reserved or confirmed -- and the trip has
 * not ended. Once boarded there is nothing to track; you are the dot.
 */
final class BookingState
{
    public const RESERVED = 'reserved';

    public const CONFIRMED = 'confirmed';

    public const BOARDED = 'boarded';

    public const COMPLETED = 'completed';

    public const NOT_BOARDED = 'not_boarded';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    /** Queue statuses on which a trip is over and nothing is coming. */
    private const TRIP_OVER = ['Completed', 'Cancelled'];

    public static function of(Booking $booking): string
    {
        if (! (bool) $booking->status) {
            $reason = $booking->cancellation_reason;
            $reason = $reason instanceof BookingCancellationReason ? $reason : BookingCancellationReason::tryFrom((string) $reason);

            return match ($reason) {
                BookingCancellationReason::NoShow => self::NOT_BOARDED,
                BookingCancellationReason::Expired => self::EXPIRED,
                // Cancelled, or a row from before the reason was recorded: an
                // unpaid one can only have expired; a paid one was cancelled.
                BookingCancellationReason::Cancelled => self::CANCELLED,
                default => (bool) $booking->paid ? self::CANCELLED : self::EXPIRED,
            };
        }

        if ((bool) $booking->boarded) {
            return self::tripOver($booking) ? self::COMPLETED : self::BOARDED;
        }

        return (bool) $booking->paid ? self::CONFIRMED : self::RESERVED;
    }

    /** Is there a bus to show on a map for this booking, right now? */
    public static function trackable(Booking $booking): bool
    {
        return in_array(self::of($booking), [self::RESERVED, self::CONFIRMED], true)
            && ! self::tripOver($booking);
    }

    /**
     * Attach `state` and `trackable` to every booking in a list. Reads the
     * queue's status only when the list already loaded it; nothing here
     * issues a query per row.
     *
     * @param  Collection<int, Booking>  $bookings
     */
    public static function annotate(Collection $bookings): Collection
    {
        foreach ($bookings as $booking) {
            self::annotateOne($booking);
        }

        return $bookings;
    }

    public static function annotateOne(Booking $booking): Booking
    {
        $booking->setAttribute('state', self::of($booking));
        $booking->setAttribute('trackable', self::trackable($booking));

        return $booking;
    }

    private static function tripOver(Booking $booking): bool
    {
        if (! $booking->relationLoaded('queue') || $booking->queue === null) {
            return false;
        }

        $queue = $booking->queue;
        $status = $queue->relationLoaded('queue_status') ? $queue->queue_status?->status : null;

        if ($status !== null) {
            return in_array($status, self::TRIP_OVER, true);
        }

        return $queue->end_time !== null;
    }
}
