<?php

namespace App\Console\Commands;

use App\Enums\BookingCancellationReason;
use App\Events\BookingCancelled;
use App\Models\Booking;
use Illuminate\Console\Command;

/**
 * Frees seats held by unpaid bookings that have sat past the hold window. The
 * occupancy query already treats expired holds as free at read time; this sweep
 * makes the release durable by flipping the stale bookings inactive. Runs across
 * all brands/SACCOs (no request context), hence withoutGlobalScopes().
 *
 * The mass update() below fires NO model events — that is the whole reason
 * Booking::booted()'s cancellation hook cannot see this path, and why the ids
 * are collected first and BookingCancelled dispatched by hand afterwards.
 * Without that, a passenger's seat was released every minute of every day and
 * they were never told.
 */
class ReleaseExpiredBookings extends Command
{
    protected $signature = 'bookings:release-expired';

    protected $description = 'Release seats held by unpaid bookings older than the hold window';

    public function handle(): int
    {
        $cutoff = now()->subMinutes((int) config('booking.hold_minutes', 10));

        $expiring = Booking::withoutGlobalScopes()
            ->where('status', true)
            ->where('paid', false)
            ->where('created_at', '<', $cutoff);

        // Snapshot the ids BEFORE the update. Afterwards status is already false
        // and the same predicate matches nothing, so there would be no way left
        // to say who was cancelled.
        $ids = (clone $expiring)->pluck('id');

        $released = $expiring->update(['status' => false]);

        // One event per booking, after the write. update() bypasses Eloquent's
        // model events entirely, so nothing else in the system will ever announce
        // these — not the model hook, not a controller.
        //
        // withoutGlobalScopes() mirrors the sweep's own read exactly. SaccoScope
        // and BrandScope both self-disable in console today (no auth user, no
        // brand Context), so this changes nothing now — but if that ever stops
        // being true, the announcement must not silently cover fewer bookings
        // than the update did. The bound is the id list this command just wrote,
        // not a user-supplied filter, so no tenancy boundary is being crossed.
        // where('status', false) is a RACE GUARD, not a tautology. Between the
        // pluck and the update an M-Pesa callback can mark one of these bookings
        // paid, and the update's own where('paid', false) then skips it — leaving
        // an id in $ids whose booking is alive and paid for. Announcing a
        // cancellation to a passenger who has just paid is the worst possible
        // wrong message, so only rows that really did go inactive are announced.
        Booking::withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->where('status', false)
            ->with('queue')
            ->chunkById(200, function ($bookings): void {
                foreach ($bookings as $booking) {
                    BookingCancelled::dispatch($booking, BookingCancellationReason::Expired);
                }
            });

        $this->info("Released {$released} expired booking hold(s).");

        return self::SUCCESS;
    }
}
