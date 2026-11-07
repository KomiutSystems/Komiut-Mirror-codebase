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
 * Raised whenever a booking transitions to paid (any rail). Drives loyalty
 * earning. A ride settled with points also fires this, but the earn listener
 * skips it (the redeemed ledger row is already present).
 *
 * It is ALSO what moves a row onto the crew's "To collect" tab, which is why
 * it broadcasts. That tab is `confirmed` -- paid and not yet boarded -- so
 * BookingCreated alone would never populate it: a held seat is `reserved`,
 * and the crew would keep seeing an empty list while a paid passenger waited
 * at the stage (observed 2026-11-07 on KDL 126J).
 *
 * Same channel and the same payload shape as BookingCreated, so the app
 * handles "a booking appeared" and "it is now paid" with one parser and the
 * row simply changes tab. Broadcasting does not disturb the listeners: they
 * receive this event exactly as before.
 *
 * PLAIN ShouldBroadcast -- see the note on BookingCreated about
 * ShouldBroadcastAfterCommit not existing in this framework version.
 */
class BookingPaid implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Booking $booking) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        $vehicleId = CrewBookingPayload::vehicleId($this->booking);

        if ($vehicleId === null) {
            return [];
        }

        return [new PrivateChannel('vehicle.'.$vehicleId)];
    }

    public function broadcastAs(): string
    {
        return 'booking.paid';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return CrewBookingPayload::for($this->booking);
    }
}
