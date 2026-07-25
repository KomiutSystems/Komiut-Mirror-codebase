<?php

declare(strict_types=1);

namespace Tests\Feature\Queues;

use App\Enums\BookingType;
use App\Models\Booking;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;

/**
 * A booking's type is decided by the matatu's state when it is made: still at
 * the terminus (queue Pending) → 'route'; already departed and broadcasting
 * (queue Active) → 'pick_as_you_go'. Set once at creation, from the queue status.
 */
final class BookingTypeTest extends QueueTestCase
{
    private function book(array $world, int $queueId, string $seat): void
    {
        $this->postJson('/api/auth/book_a_ride/booking/add', [
            'id' => $queueId,
            'seats' => $seat,
            'name' => 'Wanjiku',
            'phone' => '0722123456',
        ])->assertOk();
    }

    #[Test]
    public function booking_at_the_terminus_is_a_route_booking(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        Sanctum::actingAs($this->makeUser([], $world['sacco']));

        $this->book($world, $queue->id, (string) $world['arrangements'][0]->id);

        $this->assertSame(BookingType::Route, Booking::firstOrFail()->booking_type);
    }

    #[Test]
    public function booking_a_departed_matatu_is_pick_as_you_go(): void
    {
        $world = $this->makeWorld();
        $active = $this->makeQueueStatus('Active', 'Active');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $active, $world['owner']);
        Sanctum::actingAs($this->makeUser([], $world['sacco']));

        $this->book($world, $queue->id, (string) $world['arrangements'][0]->id);

        $this->assertSame(BookingType::PickAsYouGo, Booking::firstOrFail()->booking_type);
    }

    #[Test]
    public function the_type_is_fixed_at_creation_even_if_the_queue_later_departs(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $active = $this->makeQueueStatus('Active', 'Active');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        Sanctum::actingAs($this->makeUser([], $world['sacco']));

        $this->book($world, $queue->id, (string) $world['arrangements'][0]->id);
        $booking = Booking::firstOrFail();

        // The matatu departs, then the same booking is amended — type must not flip.
        $queue->update(['queue_status_id' => $active->id]);
        $this->postJson('/api/auth/book_a_ride/booking/add', [
            'id' => $queue->id,
            'booking_id' => $booking->id,
            'seats' => (string) $world['arrangements'][1]->id,
            'name' => 'Wanjiku',
            'phone' => '0722123456',
        ])->assertOk();

        $this->assertSame(BookingType::Route, $booking->fresh()->booking_type);
    }
}
