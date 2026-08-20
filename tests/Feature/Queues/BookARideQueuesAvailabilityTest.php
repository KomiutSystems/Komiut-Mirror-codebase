<?php

declare(strict_types=1);

namespace Tests\Feature\Queues;

use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;

/**
 * Regression coverage for the per-queue real free-seat count added to
 * App\Http\Controllers\APIs\Dashboard\BookARide\BookARideQueuesAPIController::getQueues.
 *
 * The passenger "book a ride" list must report `available_seats` (real seats
 * free right now, via SegmentSeatAvailability) so the app never falls back to
 * raw vehicle capacity and shows a full matatu as bookable.
 */
final class BookARideQueuesAvailabilityTest extends QueueTestCase
{
    #[Test]
    public function an_empty_queue_reports_the_full_capacity_as_available(): void
    {
        $world = $this->makeWorld(); // seat layout: 4 seats / 4 arrangements
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);

        Sanctum::actingAs($this->makeUser([], $world['sacco']));

        $this->getJson('/api/auth/book_a_ride/queues')
            ->assertOk()
            ->assertJsonCount(1, 'queues')
            ->assertJsonPath('queues.0.id', $queue->id)
            ->assertJsonPath('queues.0.total_seats', 4)
            ->assertJsonPath('queues.0.available_seats', 4);
    }

    #[Test]
    public function available_seats_is_capacity_minus_the_seats_already_booked(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);

        // Two of the four seats taken end-to-end on this queue.
        $booking = $this->makeBooking($queue, $world['owner'], $world['from'], $world['to'], 'Otieno');
        $this->makeSeatBooking($booking, $world['arrangements'][0]);
        $this->makeSeatBooking($booking, $world['arrangements'][1]);

        Sanctum::actingAs($this->makeUser([], $world['sacco']));

        $this->getJson('/api/auth/book_a_ride/queues')
            ->assertOk()
            ->assertJsonPath('queues.0.total_seats', 4)
            ->assertJsonPath('queues.0.available_seats', 2);
    }

    #[Test]
    public function a_fully_booked_queue_reports_zero_available(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);

        // Every seat taken end-to-end.
        $booking = $this->makeBooking($queue, $world['owner'], $world['from'], $world['to'], 'Otieno');
        foreach ($world['arrangements'] as $arrangement) {
            $this->makeSeatBooking($booking, $arrangement);
        }

        Sanctum::actingAs($this->makeUser([], $world['sacco']));

        $this->getJson('/api/auth/book_a_ride/queues')
            ->assertOk()
            ->assertJsonPath('queues.0.total_seats', 4)
            ->assertJsonPath('queues.0.available_seats', 0);
    }

    #[Test]
    public function occupancy_is_segment_aware_when_a_pickup_and_dropoff_are_given(): void
    {
        // stages: from@0, Ruiru@20, to@40
        $world = $this->makeWorld();
        $mid = $this->makePlace('Ruiru');
        $this->makeRouteStage($world['route'], $mid, 20);
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);

        // One seat taken only on the first leg: origin -> Ruiru.
        $booking = $this->makeBooking($queue, $world['owner'], $world['from'], $mid, 'Otieno');
        $this->makeSeatBooking($booking, $world['arrangements'][0]);

        Sanctum::actingAs($this->makeUser([], $world['sacco']));

        // The disjoint later leg (Ruiru -> Thika) does not overlap that booking,
        // so all four seats are free for it.
        $this->getJson('/api/auth/book_a_ride/queues?from_id='.$mid->id.'&to_id='.$world['to']->id)
            ->assertOk()
            ->assertJsonPath('queues.0.available_seats', 4);

        // The overlapping leg (origin -> Ruiru) sees the seat as taken: 3 free.
        $this->getJson('/api/auth/book_a_ride/queues?from_id='.$world['from']->id.'&to_id='.$mid->id)
            ->assertOk()
            ->assertJsonPath('queues.0.available_seats', 3);
    }
}
