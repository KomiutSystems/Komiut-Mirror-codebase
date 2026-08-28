<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\BookingCancellationReason;
use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised when a booking stops being active (bookings.status true -> false), so
 * its seats go back on sale.
 *
 * TWO OF THE THREE CANCELLATION PATHS CANNOT SELF-REPORT. Booking::booted()
 * catches the model-level flip, but the two scheduled sweeps cancel in bulk with
 * a mass `update()`, which issues one UPDATE and fires NO model events at all:
 *
 *   - App\Console\Commands\ReleaseExpiredBookings (bookings:release-expired,
 *     every minute)   — Booking::withoutGlobalScopes()->...->update(['status' => false])
 *   - App\Console\Commands\CheckPassengerPayments (app:check-passenger-payments,
 *     every 2 minutes) — Booking::whereIn('id', $ids)->update(['status' => 0])
 *
 * Both therefore collect the affected ids and dispatch this event explicitly,
 * per booking, after the update. If you add a third bulk path, it must do the
 * same or the passenger is never told their seat was released.
 */
class BookingCancelled
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Booking $booking,
        public BookingCancellationReason $reason = BookingCancellationReason::Cancelled,
    ) {}
}
