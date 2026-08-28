<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\QueueStatus;
use App\Models\SeatBooking;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Cancels bookings on a live queue that are still unpaid two minutes after they
 * were made, and releases the seats they were holding.
 *
 * The unpaid predicate is stated TWICE on purpose — once to choose the rows and
 * again on each write. Reading and writing are two round trips, and an M-Pesa
 * callback (App\Http\Controllers\APIs\MpesaPaymentsController) settles bookings
 * concurrently, so a booking that is unpaid when the ids are plucked can be paid
 * by the time the update runs. Without the re-statement that update still
 * cancelled it: a passenger whose money had already left their phone lost the
 * seat, and the loss was silent because the sweep reported nothing.
 *
 * Order matters as much as the predicate. Bookings are cancelled first, seats
 * second: SegmentSeatAvailability treats a booking with status = 0 as vacating
 * its seats regardless of the seat rows, so releasing seats first would open a
 * window where a still-live booking's seat could be sold twice.
 *
 * The sibling sweep, ReleaseExpiredBookings, has never had this problem — it
 * touches one table, so its predicate and its write are the same statement.
 */
class CheckPassengerPayments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:check-passenger-payments';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cancel bookings left unpaid past the payment window and free their seats';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $date = Carbon::now();
        $date = $date->subMinutes(2);
        $statuses = QueueStatus::where('status', 'Active')->orWhere('status', 'Pending')->pluck('id');
        $bookingIds = Booking::whereHas('queue', function($query) use($statuses){
            $query->whereIn('queue_status_id', $statuses);
        })->where('paid', 0)->where('created_at', '<=', $date)->pluck('id');

        $now = Carbon::now();

        // Re-stated, not inherited from the pluck: anything settled since then
        // is no longer ours to cancel.
        $cancelled = Booking::whereIn('id', $bookingIds)
            ->where('paid', false)
            ->update(['status'=>0, 'updated_at'=>$now]);

        // Same guard, reached through the booking — seat_bookings carries its own
        // `paid` column but the M-Pesa callback only ever writes the booking's.
        $released = SeatBooking::whereIn('booking_id', $bookingIds)
            ->whereExists(function (QueryBuilder $query) {
                $query->selectRaw('1')
                    ->from('bookings')
                    ->whereColumn('bookings.id', 'seat_bookings.booking_id')
                    ->where('bookings.paid', false);
            })
            ->update(['status'=>0, 'updated_at'=>$now]);

        $this->info("Cancelled {$cancelled} unpaid booking(s); released {$released} seat hold(s).");

        return self::SUCCESS;
    }
}
