<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised once, when a booking row is first written — i.e. when seats are held,
 * BEFORE payment.
 *
 * Fired from Booking::booted()'s `created` hook rather than from the controller
 * so it cannot be forgotten by the next booking path someone adds. addBooking()
 * in BookARideQueuesAPIController reuses one method for create AND edit
 * (`$request->booking_id > 0 ? Booking::find(...) : new Booking`), so a
 * controller-side dispatch would have had to re-derive "is this new?" — the
 * model already knows.
 */
class BookingCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public Booking $booking) {}
}
