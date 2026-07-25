<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\Queue;
use App\Models\RouteStage;

/**
 * "Pick as you go" seat availability.
 *
 * A physical seat is only taken for the *segment* a passenger rides. Two
 * bookings can share one seat if their pickup→dropoff spans don't overlap
 * (A rides CBD→Ruiru, B rides Juja→Thika — same seat). Occupancy is therefore
 * an interval-overlap test on each stop's route position, not a whole-trip flag.
 *
 * Position is the stage `sequence` (travel order), not straight-line distance —
 * distance mis-orders stops on a route that curves back and would then let two
 * genuinely-overlapping segments look disjoint and share a seat.
 *
 * When a segment can't be resolved (no from/to given, or a stop that isn't on
 * the route's stages) it falls back to the full route [0, ∞): the safe, no-reuse
 * behaviour, so we never oversell by mis-reading a segment.
 */
final class SegmentSeatAvailability
{
    /** @var array<int, array<int, int>> route_id => (place_id => sequence) */
    private array $positionCache = [];

    /**
     * Seat ids that are unavailable for the requested pickup→dropoff on this
     * queue, honouring the unpaid-hold window.
     *
     * @return array<int, int>
     */
    public function occupiedSeatIds(Queue $queue, ?int $fromPlaceId, ?int $toPlaceId, ?int $excludeBookingId = null): array
    {
        [$reqFrom, $reqTo] = $this->interval($queue, $fromPlaceId, $toPlaceId);
        $cutoff = now()->subMinutes((int) config('booking.hold_minutes', 10));

        $bookings = Booking::withoutGlobalScopes()
            ->where('queue_id', $queue->id)
            ->where('status', true)
            ->where(function ($q) use ($cutoff) {
                $q->where('paid', true)->orWhere('created_at', '>=', $cutoff);
            })
            ->when($excludeBookingId !== null, fn ($q) => $q->where('id', '<>', $excludeBookingId))
            ->with('seats')
            ->get(['id', 'queue_id', 'from_id', 'to_id', 'paid', 'created_at', 'status']);

        $occupied = [];
        foreach ($bookings as $booking) {
            [$bFrom, $bTo] = $this->interval($queue, $booking->from_id, $booking->to_id);

            // Half-open interval overlap: [a,b) ∩ [c,d) ≠ ∅  ⇔  a < d ∧ c < b.
            if ($reqFrom < $bTo && $bFrom < $reqTo) {
                foreach ($booking->seats as $seatBooking) {
                    if ($seatBooking->status) {
                        $occupied[(int) $seatBooking->seat_id] = true;
                    }
                }
            }
        }

        return array_keys($occupied);
    }

    public function isTaken(Queue $queue, int $seatId, ?int $fromPlaceId, ?int $toPlaceId, ?int $excludeBookingId = null): bool
    {
        return in_array($seatId, $this->occupiedSeatIds($queue, $fromPlaceId, $toPlaceId, $excludeBookingId), true);
    }

    /**
     * Resolve a pickup→dropoff pair to a [from, to] position interval on the
     * route. Unknown/absent stops collapse to the full route (conservative).
     *
     * @return array{0: float, 1: float}
     */
    private function interval(Queue $queue, ?int $fromPlaceId, ?int $toPlaceId): array
    {
        if ($fromPlaceId === null || $toPlaceId === null) {
            return [0.0, INF];
        }

        $positions = $this->positions((int) $queue->route_id);
        $from = $positions[$fromPlaceId] ?? null;
        $to = $positions[$toPlaceId] ?? null;

        if ($from === null || $to === null) {
            return [0.0, INF];
        }
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [(float) $from, (float) $to];
    }

    /**
     * @return array<int, int> place_id => travel-order position along the route
     */
    private function positions(int $routeId): array
    {
        if (! isset($this->positionCache[$routeId])) {
            $this->positionCache[$routeId] = RouteStage::where('route_id', $routeId)
                ->get(['place_id', 'sequence'])
                ->mapWithKeys(fn (RouteStage $s) => [(int) $s->place_id => (int) $s->sequence])
                ->all();
        }

        return $this->positionCache[$routeId];
    }
}
