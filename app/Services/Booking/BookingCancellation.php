<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Enums\BookingCancellationReason;
use App\Events\BookingCancelled;
use App\Models\Booking;
use App\Models\Queue;
use App\Models\QueueStatus;
use App\Models\SeatBooking;
use App\Services\Loyalty\LoyaltyService;
use Illuminate\Support\Facades\Log;

/**
 * A passenger who paid and never rode gets their seat released and their
 * money back -- through ONE path, whichever way the trip ended on them.
 *
 * There were seven ways a trip could end and exactly one of them settled the
 * passengers on it. The crew's trip/end refuses to finish with paid, unmarked
 * passengers and refunds on `unmarked: no_show`; every other route -- the bus
 * pulling out of a Pending queue, a new driver signing into the vehicle, the
 * stale-queue sweep, the dashboard completing or replacing a queue, the crew's
 * last-stop pick-up -- flipped the queue and left every `confirmed` booking on
 * it exactly there: money kept, seat consumed, no refund, no notification,
 * `trackable` false and a map with nothing on it. Found on the 2026-09-12
 * prod-readiness pass, before a real passenger hit it.
 *
 * notBoarded() is the write DriverTripController::noShow() always did, with
 * the reason as a parameter. settleTripOver() applies it to everything still
 * live on a queue that has just ended; Queue::booted() calls it on every
 * transition to a trip-over status, so no caller has to remember.
 *
 * ON AUTO-REFUNDING. The trip-end gate exists because "unmarked" is ambiguous
 * -- a no-show the conductor forgot to record, or a rider the conductor forgot
 * to board -- and refunding the second kind is a collusion move. That gate
 * stays, and it is the path the crew uses. This is the net beneath it, for
 * the ends nobody at the door decided: on those the choice is between
 * refunding a rider who was not boarded (the SACCO loses a fare it could not
 * prove) and keeping money from a passenger who never rode (the passenger
 * loses a fare they can prove). The policy since 2026-09-11 is the first.
 */
final class BookingCancellation
{
    /** Queue statuses on which a trip is over: nothing more will be picked up. */
    public const TRIP_OVER_STATUSES = ['Completed', 'Cancelled'];

    public function __construct(private readonly LoyaltyService $loyalty) {}

    /**
     * Cancel a live, unboarded booking, release its seats, refund what was
     * paid, and tell the passenger -- or do NOTHING if it was not live and
     * unboarded at the moment of the write.
     *
     * QUERY BUILDER, NOT $row->update(). Booking::booted() dispatches
     * BookingCancelled(Cancelled) on any Eloquent save that flips status, and
     * this dispatches its own with the real reason, so a model write here would
     * announce every cancellation twice. The two expiry sweeps use the same
     * pattern for the same reason.
     *
     * CONDITIONAL, and everything below hangs off it. Only a live, unboarded
     * booking can be cancelled this way; the WHERE is the state guard and the
     * row count is the one answer to "did that happen" that a concurrent board
     * or cancel cannot fake. A passenger who paid and RODE is never refunded
     * the ride they took.
     */
    public function notBoarded(Booking $row, BookingCancellationReason $reason): bool
    {
        $affected = Booking::withoutGlobalScopes()
            ->whereKey($row->id)
            ->where('status', true)
            ->where('boarded', false)
            ->update([
                'status' => false,
                'cancellation_reason' => $reason->value,
                'cancelled_at' => now(),
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            return false;
        }

        SeatBooking::where('booking_id', $row->id)->update(['status' => false]);

        // NOT BOARDED MEANS REFUNDED. Their points if they paid points, the
        // fare's worth in points if they paid money. Idempotent at the ledger.
        // Wrapped: the seat is released and the row cancelled above, and a
        // refund failure must not report the cancellation as failed --
        // bookings:repair-refunds finds every cancelled-for-refund booking with
        // no Refunded row and tries again.
        //
        // The RESULT is kept because it is not always a refund: null for an
        // unpaid booking, for a SACCO with no priced loyalty program, and (via
        // the catch) for a ledger failure. The notification is worded off it,
        // so a passenger is never told their money is back when it is not.
        $refund = null;
        if ($reason->refunds()) {
            try {
                $refund = $this->loyalty->refundForBooking($row);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        BookingCancelled::dispatch(
            $row->fresh(),
            $reason,
            $refund === null ? null : (float) $refund->value,
        );

        return true;
    }

    /**
     * The trip on this queue is over: settle whoever is still waiting on it.
     *
     *   confirmed (paid, not boarded)  -> cancelled, TripOver, REFUNDED
     *   reserved  (unpaid)             -> cancelled, TripOver, nothing to refund
     *   boarded                        -> untouched; they rode
     *
     * The unpaid ones matter too: left `reserved` on a dead trip they stayed
     * payable for up to ten minutes, and a payment landing then was money on a
     * ride nobody would ever take (see the trip-over guards in redeem, the STK
     * push and its callback).
     *
     * @return array{refunded: int, released: int}
     */
    public function settleTripOver(Queue $queue): array
    {
        $live = Booking::withoutGlobalScopes()
            ->where('queue_id', $queue->id)
            ->where('status', true)
            ->where('boarded', false)
            ->get();

        $counts = ['refunded' => 0, 'released' => 0];

        foreach ($live as $booking) {
            if (! $this->notBoarded($booking, BookingCancellationReason::TripOver)) {
                continue;
            }
            $counts[(bool) $booking->paid ? 'refunded' : 'released']++;
        }

        if ($counts['refunded'] > 0 || $counts['released'] > 0) {
            Log::info('trip over: settled waiting bookings', ['queue_id' => (int) $queue->id] + $counts);
        }

        return $counts;
    }

    /** Whether this queue is on a status that means the trip is over. */
    public static function isTripOver(?Queue $queue): bool
    {
        if ($queue === null) {
            return true;
        }

        $status = $queue->relationLoaded('queue_status')
            ? $queue->queue_status?->status
            : QueueStatus::withoutGlobalScopes()->whereKey($queue->queue_status_id)->value('status');

        return $status === null || in_array($status, self::TRIP_OVER_STATUSES, true);
    }
}
