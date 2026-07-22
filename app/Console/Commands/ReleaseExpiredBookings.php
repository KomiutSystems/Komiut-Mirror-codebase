<?php

namespace App\Console\Commands;

use App\Models\Booking;
use Illuminate\Console\Command;

/**
 * Frees seats held by unpaid bookings that have sat past the hold window. The
 * occupancy query already treats expired holds as free at read time; this sweep
 * makes the release durable by flipping the stale bookings inactive. Runs across
 * all brands/SACCOs (no request context), hence withoutGlobalScopes().
 */
class ReleaseExpiredBookings extends Command
{
    protected $signature = 'bookings:release-expired';

    protected $description = 'Release seats held by unpaid bookings older than the hold window';

    public function handle(): int
    {
        $cutoff = now()->subMinutes((int) config('booking.hold_minutes', 10));

        $released = Booking::withoutGlobalScopes()
            ->where('status', true)
            ->where('paid', false)
            ->where('created_at', '<', $cutoff)
            ->update(['status' => false]);

        $this->info("Released {$released} expired booking hold(s).");

        return self::SUCCESS;
    }
}
