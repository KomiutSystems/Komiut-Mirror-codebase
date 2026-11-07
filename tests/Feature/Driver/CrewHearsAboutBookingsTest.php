<?php

declare(strict_types=1);

namespace Tests\Feature\Driver;

use App\Events\BookingCreated;
use App\Events\BookingPaid;
use App\Models\Booking;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A crew is told when someone books, instead of finding out by refreshing.
 *
 * The booking list was fetch-only. On 2026-11-07 a driver on KDL 126J sat on
 * "Nobody is waiting to be picked up yet" while a passenger who had already
 * booked and paid stood at Alsops: trips/bookings returned her correctly, and
 * the phone simply never asked again. Nothing moved the row onto his screen.
 *
 * Two events, because the crew's tabs are two: a held seat is `reserved`
 * (BookingCreated) and only a paid one reaches "To collect" (BookingPaid), so
 * announcing creation alone would leave that tab exactly as empty as before.
 */
final class CrewHearsAboutBookingsTest extends QueueTestCase
{
    /** @return array{0: Booking, 1: array<string, mixed>} */
    private function bookingOnALiveRun(): array
    {
        $world = $this->makeWorld();
        $active = $this->makeQueueStatus('Active '.$this->nextSequence(), 'Active');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $active, $world['owner']);

        return [$this->makeBooking($queue, $world['owner'], $world['from'], $world['to'], 'Marylyne'), $world];
    }

    #[Test]
    public function a_new_booking_is_announced_on_its_bus_channel(): void
    {
        Event::fake([BookingCreated::class]);

        [$booking, $world] = $this->bookingOnALiveRun();

        Event::assertDispatched(BookingCreated::class, function (BookingCreated $e) use ($booking, $world) {
            $channels = $e->broadcastOn();

            return $e->booking->id === $booking->id
                && $e->broadcastAs() === 'booking.created'
                && count($channels) === 1
                && $channels[0] instanceof PrivateChannel
                && (string) $channels[0] === 'private-vehicle.'.$world['vehicle']->id;
        });
    }

    #[Test]
    public function paying_announces_the_row_that_reaches_to_collect(): void
    {
        [$booking, $world] = $this->bookingOnALiveRun();

        Event::fake([BookingPaid::class]);
        $booking->forceFill(['paid' => true])->save();

        Event::assertDispatched(BookingPaid::class, function (BookingPaid $e) use ($world) {
            $channels = $e->broadcastOn();

            return $e->broadcastAs() === 'booking.paid'
                && count($channels) === 1
                && (string) $channels[0] === 'private-vehicle.'.$world['vehicle']->id;
        });
    }

    #[Test]
    public function the_payload_carries_the_stop_names(): void
    {
        [$booking, $world] = $this->bookingOnALiveRun();

        // A queued broadcast restores the model from its id and carries NO
        // relations, so this is the shape the crew actually receives. Built
        // from a fresh fetch on purpose: with whenLoaded() and nothing loaded,
        // pickup and dropoff arrive null and every pushed row reads
        // "Pickup -> Dropoff" with no stop on it.
        $fresh = Booking::withoutGlobalScopes()->findOrFail($booking->id);
        $payload = (new BookingCreated($fresh))->broadcastWith();

        $this->assertSame($booking->id, $payload['bookingId']);
        $this->assertSame('Marylyne', $payload['passengerName']);
        $this->assertSame($world['from']->name, $payload['pickup']['name'] ?? null);
        $this->assertSame($world['to']->name, $payload['dropoff']['name'] ?? null);
        // The crew's tabs key off this, not off `status`.
        $this->assertSame('reserved', $payload['status_label']);
    }

    #[Test]
    public function a_paid_booking_announces_itself_as_confirmed(): void
    {
        [$booking] = $this->bookingOnALiveRun();
        $booking->forceFill(['paid' => true])->save();

        $fresh = Booking::withoutGlobalScopes()->findOrFail($booking->id);
        $payload = (new BookingPaid($fresh))->broadcastWith();

        $this->assertSame('confirmed', $payload['status_label'], 'this is what puts it on "To collect"');
        $this->assertSame('PAID', $payload['status']);
    }

    #[Test]
    public function a_booking_on_no_queue_is_announced_to_nobody(): void
    {
        [$booking] = $this->bookingOnALiveRun();

        // In memory only: the guard is what is under test, not whether the
        // column accepts a null, so this must not depend on the schema.
        $booking->queue_id = null;

        // No queue means no bus, so there is no crew to tell. It must return an
        // empty channel list rather than throw inside a queue worker.
        $this->assertSame([], (new BookingCreated($booking))->broadcastOn());
        $this->assertSame([], (new BookingPaid($booking))->broadcastOn());
    }
}
