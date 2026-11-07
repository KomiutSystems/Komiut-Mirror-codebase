<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Booking;
use App\Support\CrewBookingPayload;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A seat was held on a bus -- pushed to its crew the moment it is.
 *
 * The crew's booking list was fetch-only: nothing moved a new row onto it, so
 * a driver watching the screen saw "Nobody is waiting to be picked up yet"
 * while a passenger stood at the stage having already booked. Observed on
 * 2026-11-07, KDL 126J: the booking was on the bus's live run and returned
 * correctly by trips/bookings, and the phone simply never asked again.
 *
 * This one says RESERVED -- a held seat, no money yet. BookingPaid beside it
 * is what moves the row into "To collect"; both are needed, because a crew
 * that only hears about paid bookings cannot see who is still deciding.
 *
 * `vehicle.{id}` and not `trip.{queueId}`, matching PaymentRecorded: the bus
 * is the thing the crew is attached to, the channel is already authorised for
 * anyone holding an open assignment to it (routes/channels.php), and the app
 * is already subscribed to it for fares.
 *
 * PLAIN ShouldBroadcast. Laravel 13 ships ShouldBroadcast and
 * ShouldBroadcastNow and nothing else -- PaymentRecorded's first attempt died
 * silently on a ShouldBroadcastAfterCommit that does not exist in this
 * framework, and was reverted after four red runs. Do not reach for it here.
 */
class BookingCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Booking $booking) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        $vehicleId = CrewBookingPayload::vehicleId($this->booking);

        // A booking that hangs off no queue belongs to no bus, so there is no
        // crew to tell. It is not lost; the list is still the source of truth.
        if ($vehicleId === null) {
            return [];
        }

        return [new PrivateChannel('vehicle.'.$vehicleId)];
    }

    public function broadcastAs(): string
    {
        return 'booking.created';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return CrewBookingPayload::for($this->booking);
    }
}
