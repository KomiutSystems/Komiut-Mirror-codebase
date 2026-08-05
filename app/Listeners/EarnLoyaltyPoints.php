<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\BookingPaid;
use App\Models\Booking;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Super\Money\LoyaltyEarnFailureTracker;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Credit loyalty points for a paid booking — without ever endangering the payment
 * that triggered it.
 *
 * BookingPaid fires from the Booking::updated model event, which in the reconcile
 * path runs INSIDE the settlement DB transaction. Two guards keep earning off the
 * payment's critical path:
 *   - a savepoint (nested transaction) around the earn, so any failure rolls back
 *     only the points work and leaves the surrounding payment transaction intact —
 *     a bare exception here would otherwise abort the whole settlement (on Postgres
 *     a failed statement poisons the transaction until rollback);
 *   - a catch that logs and drops the failure, so it can never propagate to the
 *     payment caller and turn a completed payment into an error/retry.
 *
 * Kept synchronous (NOT ShouldQueue) on purpose: Booking is brand-scoped
 * (brandVia = queue.vehicle) and a queue worker has no brand context, so restoring
 * the booking there would fail under BrandScope and the points would never earn.
 * Running in-process preserves the brand context.
 */
class EarnLoyaltyPoints
{
    public function __construct(private LoyaltyService $loyalty) {}

    public function handle(BookingPaid $event): void
    {
        // Super-console compensating control: earning is silent-on-failure (below),
        // so track a rolling attempt/failure rate per brand and alert when it spikes.
        // This runs inside the settlement transaction — it must never propagate, so
        // the brand resolution (a DB read) is guarded just like the earn itself.
        $brand = null;
        try {
            $brand = $this->brandFor($event->booking);
            app(LoyaltyEarnFailureTracker::class)->recordAttempt($brand);
        } catch (Throwable $e) {
            report($e); // tracking must never disturb the payment
        }

        try {
            DB::transaction(fn () => $this->loyalty->earnForBooking($event->booking));
        } catch (Throwable $e) {
            report($e); // a loyalty failure must never disturb the payment
            app(LoyaltyEarnFailureTracker::class)->recordFailure($brand);
        }
    }

    /** Booking is brand-scoped via queue.vehicle; prefer the active context. */
    private function brandFor(Booking $booking): ?string
    {
        if (Context::has('brand')) {
            return (string) Context::get('brand');
        }

        $booking->loadMissing('queue.vehicle');
        $brand = $booking->queue?->vehicle?->brand;

        return $brand !== null ? (string) $brand : null;
    }
}
