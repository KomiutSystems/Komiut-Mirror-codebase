<?php

declare(strict_types=1);

namespace Tests\Feature\Queues;

use App\Services\Booking\SegmentSeatAvailability;
use PHPUnit\Framework\Attributes\Test;

/**
 * Seat-segment overlap must use travel order (RouteStage.sequence), not
 * straight-line distance. On a route that curves back toward the origin, a later
 * stop can have a SMALLER distance than an earlier one — ordering by distance
 * would then judge two overlapping rides as disjoint and hand them the same seat.
 *
 * (makeWorld already seeds the route's from/to at sequences 1–2, so these tests
 * add their own stops at higher sequences to keep the ordering unambiguous.)
 */
final class SegmentSequenceTest extends QueueTestCase
{
    #[Test]
    public function overlap_follows_sequence_even_when_distance_would_mis_order(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue(
            $world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']
        );

        $a = $this->makePlace('Start ' . $this->nextSequence());
        $b = $this->makePlace('Bend ' . $this->nextSequence());
        $c = $this->makePlace('Loopback ' . $this->nextSequence());
        // Travel order a → b → c, but c loops back nearer the origin than b:
        // distance ordering (b=60 > c=20) would flip the b→c interval.
        $this->makeRouteStage($world['route'], $a, 0, sequence: 3);
        $this->makeRouteStage($world['route'], $b, 60, sequence: 4);
        $this->makeRouteStage($world['route'], $c, 20, sequence: 5);

        $seat = $world['arrangements'][0];

        // Rider 1 books a → c (sequence 3..5), occupying the whole run on this seat.
        $booking = $this->makeBooking($queue, $world['owner'], $a, $c, 'Rider1');
        $this->makeSeatBooking($booking, $seat);

        $availability = app(SegmentSeatAvailability::class);

        // Rider 2 wants b → c (sequence 4..5): overlaps rider 1, must read taken.
        // Distance ordering (b=60, c=20) would have freed the seat.
        $this->assertTrue(
            $availability->isTaken($queue, $seat->id, $b->id, $c->id),
            'a segment overlapping an existing booking must read as taken'
        );
    }

    #[Test]
    public function non_overlapping_segments_still_share_a_seat(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue(
            $world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']
        );

        $a = $this->makePlace('P1 ' . $this->nextSequence());
        $b = $this->makePlace('P2 ' . $this->nextSequence());
        $c = $this->makePlace('P3 ' . $this->nextSequence());
        $this->makeRouteStage($world['route'], $a, 10, sequence: 3);
        $this->makeRouteStage($world['route'], $b, 20, sequence: 4);
        $this->makeRouteStage($world['route'], $c, 30, sequence: 5);

        $seat = $world['arrangements'][0];

        // Rider 1: a → b. Rider 2: b → c. Disjoint (touch at b), so the seat is reused.
        $booking = $this->makeBooking($queue, $world['owner'], $a, $b, 'Rider1');
        $this->makeSeatBooking($booking, $seat);

        $availability = app(SegmentSeatAvailability::class);

        $this->assertFalse(
            $availability->isTaken($queue, $seat->id, $b->id, $c->id),
            'a segment starting where another ends does not overlap'
        );
    }
}
