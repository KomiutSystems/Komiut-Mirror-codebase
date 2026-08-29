<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\BookingPaid;
use App\Services\CarbonCredits\CarbonCreditService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Accrue platform carbon credits for a paid booking.
 *
 * Same two guards as EarnLoyaltyPoints, for the same reason: BookingPaid fires
 * from Booking::updated, which in the reconcile path runs INSIDE the settlement
 * transaction. A savepoint keeps a failure here from poisoning that transaction,
 * and the catch keeps it from turning a completed payment into an error.
 *
 * Synchronous, not queued: a worker has no brand context, and Booking is
 * brand-scoped through queue.vehicle.
 */
class EarnCarbonCredits
{
    public function __construct(private CarbonCreditService $credits) {}

    public function handle(BookingPaid $event): void
    {
        try {
            DB::transaction(fn () => $this->credits->earnForBooking($event->booking));
        } catch (Throwable $e) {
            report($e); // a rewards failure must never disturb the payment
        }
    }
}
