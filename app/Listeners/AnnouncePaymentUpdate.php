<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\BookingCancelled;
use App\Events\BookingCreated;
use App\Events\BookingPaid;
use App\Services\Payments\PassengerPayments\PaymentUpdates;

/**
 * A booking's payment moved: tell the passenger's phone (payment.updated).
 *
 * The three booking events already cover every write that can change what a
 * booking's payment reads as -- held, paid (any rail), cancelled, expired by
 * either sweep, refunded by trip/end, the trip-over settlement, the late
 * callback or bookings:repair-refunds, each of which dispatches
 * BookingCancelled after its refund is written. So this listens rather than
 * adding a call at each of those places.
 *
 * Only says WHICH booking; PaymentUpdates reads what it now is, after commit,
 * and stays quiet when nothing the passenger sees has changed (an expiry
 * while a prompt is still open, say). Never throws.
 */
class AnnouncePaymentUpdate
{
    public function handle(BookingCreated|BookingPaid|BookingCancelled $event): void
    {
        PaymentUpdates::bookingChanged((int) $event->booking->id);
    }
}
