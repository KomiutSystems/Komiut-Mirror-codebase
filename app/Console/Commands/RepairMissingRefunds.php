<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BookingCancellationReason;
use App\Enums\LoyaltyTransactionType;
use App\Enums\UserType;
use App\Events\BookingCancelled;
use App\Models\Booking;
use App\Models\LoyaltyTransaction;
use App\Models\User;
use App\Services\Loyalty\LoyaltyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Finish the refunds that did not finish.
 *
 * A not-boarded cancellation does three things in order: cancel the row and
 * release the seat, refund the ledger, tell the passenger. The refund is
 * wrapped -- a ledger failure must not report the cancellation as failed --
 * which means a paid booking can be cancelled-for-refund with no Refunded row
 * behind it: a Postgres hiccup, a deploy rolling the container mid-request,
 * a SACCO whose loyalty program had no point value at that moment. Nothing
 * retried those. This does, on the persisted reason, hourly.
 *
 * Idempotent by the ledger's unique (booking_id, 'refunded') key: a booking
 * that was in fact refunded is skipped by the query, and a race with a live
 * refund lands on the same row.
 */
class RepairMissingRefunds extends Command
{
    protected $signature = 'bookings:repair-refunds {--dry-run : List what would be refunded without writing}';

    protected $description = 'Refund paid bookings cancelled as not boarded or trip over that carry no refund';

    public function handle(LoyaltyService $loyalty): int
    {
        $owed = Booking::withoutGlobalScopes()
            ->where('status', false)
            ->where('paid', true)
            ->whereIn('cancellation_reason', [
                BookingCancellationReason::NoShow->value,
                BookingCancellationReason::TripOver->value,
            ])
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('loyalty_transactions')
                    ->whereColumn('loyalty_transactions.booking_id', 'bookings.id')
                    ->where('loyalty_transactions.type', LoyaltyTransactionType::Refunded->value);
            })
            ->orderBy('id')
            ->limit(500)
            ->get();

        if ($owed->isEmpty()) {
            $this->info('Every cancelled-for-refund booking has its refund.');

            return self::SUCCESS;
        }

        $refunded = 0;
        $unpriced = 0;
        $skipped = 0;

        foreach ($owed as $booking) {
            $user = User::withoutGlobalScopes()->find($booking->user_id);
            if ($user === null || $user->type !== UserType::Passenger) {
                $skipped++; // crew walk-ins refund nobody, by design

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line(sprintf('would refund booking #%d (user %d, KES %s, %s)', $booking->id, $booking->user_id, $booking->amount, $booking->cancellation_reason?->value));

                continue;
            }

            try {
                $refund = $loyalty->refundForBooking($booking);
            } catch (\Throwable $e) {
                report($e);
                $this->error("booking #{$booking->id}: ".$e->getMessage());

                continue;
            }

            if ($refund === null) {
                // No priced loyalty program on this SACCO: there is no rate to
                // convert the fare at. Stays owed; visible here every hour
                // until the SACCO sets a point value.
                $unpriced++;

                continue;
            }

            $refunded++;
            BookingCancelled::dispatch(
                $booking->fresh(),
                $booking->cancellation_reason ?? BookingCancellationReason::TripOver,
                (float) $refund->value,
            );
        }

        $summary = ['owed' => $owed->count(), 'refunded' => $refunded, 'unpriced' => $unpriced, 'skipped' => $skipped];
        $this->info(json_encode($summary));

        if ($unpriced > 0) {
            Log::warning('refund repair: bookings owed a refund on SACCOs with no priced loyalty program', $summary);
        }

        return self::SUCCESS;
    }
}
