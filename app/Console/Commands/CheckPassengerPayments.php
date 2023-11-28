<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\QueueStatus;
use App\Models\SeatBooking;
use Carbon\Carbon;
use Illuminate\Console\Command;

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
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $date = Carbon::now();
        $date = $date->subMinutes(2);
        $statuses = QueueStatus::where('status', 'Active')->orWhere('status', 'Pending')->pluck('id');
        $bookingIds = Booking::whereHas('queue', function($query) use($statuses){
            $query->whereIn('queue_status_id', $statuses);
        })->where('paid', 0)->where('created_at', '<=', $date)->pluck('id');
        Booking::whereIn('id', $bookingIds)->update(['status'=>0, 'updated_at'=>Carbon::now()]);
        SeatBooking::whereIn('booking_id', $bookingIds)->update(['status'=>0, 'updated_at'=>Carbon::now()]);
    }
}
