<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised whenever a booking transitions to paid (any rail). Drives loyalty
 * earning. A ride settled with points also fires this, but the earn listener
 * skips it (the redeemed ledger row is already present).
 */
class BookingPaid
{
    use Dispatchable, SerializesModels;

    public function __construct(public Booking $booking) {}
}
