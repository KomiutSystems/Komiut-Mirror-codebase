<?php

namespace App\Console\Commands;

use App\Models\Place;
use App\Models\QueueStatus;
use App\Models\Route;
use App\Models\Seat;
use App\Models\SeatArrangement;
use App\Models\Terminus;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Console\Command;
use App\Models\Queue;
use DB;

class CopyQueues extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:copy-queues';

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
        $url = "http://13.232.144.242/api/queues/copy/from";
        $queue = Queue::latest()->first();
        if ($queue != null) {
            $url = "http://13.232.144.242/api/queues/copy/from?created_at=" . urlencode($queue->created_at);
        }
        $json = json_decode(file_get_contents($url), true);
        foreach ($json["queues"] as $queue) {
            $vehicle = Vehicle::where('plate', $queue['vehicle']['plate'])->first();
            $terminus = Terminus::where('name', $queue['terminus'] != null ? $queue['terminus']['name'] : null)->first();
            $queue_status = QueueStatus::where('name', $queue['queue_status']['name'])->where('status', $queue['queue_status']['status'])->first();
            $from = Place::where('name', $queue['route']['from']['name'])->first();
            $to = Place::where('name', $queue['route']['to']['name'])->first();
            $route = Route::where('from_id', $from->id)->where('to_id', $to->id)->first();
            $user = User::where('email', $queue['user']['email'])->first();
            if ($terminus == null) {
                $terminus = Terminus::where('place_id', $from->id)->first();
                if ($terminus == null) {
                    $terminus = Terminus::first();
                }
            }
            if (
                Queue::where('vehicle_id', $vehicle->id)->where('route_id', $route->id)->where('queue_status_id', $queue_status->id)
                    ->where('terminus_id', $terminus->id)->where('user_id', $user->id)->where('created_at', Carbon::parse($queue['created_at']))->count() <= 0
            ) {
                $id = DB::table("queues")->insertGetId([
                    "queue_number" => $queue['queue_number'],
                    "vehicle_id" => $vehicle->id,
                    "terminus_id" => $terminus->id,
                    "queue_status_id" => $queue_status->id,
                    "route_id" => $route->id,
                    "user_id" => $user->id,
                    'amount' => $queue['amount'],
                    'schedule_time' => $queue['schedule_time'] != null ? Carbon::parse($queue['schedule_time']) : null,
                    'start_time' => $queue['start_time'] != null ? Carbon::parse($queue['start_time']) : null,
                    'end_time' => $queue['end_time'] != null ? Carbon::parse($queue['end_time']) : null,
                    'queue_type' => $queue['queue_type'],
                    "created_at" => $queue['created_at'] != null ? Carbon::parse($queue['created_at']) : Carbon::parse($queue['start_time']),
                    "updated_at" => Carbon::parse($queue['updated_at'])
                ]);
                if ($id > 0) {
                    foreach ($queue['bookings'] as $booking) {
                        $booking_from = null;
                        if ($booking['from'] != null) {
                            $booking_from = Place::where('name', $booking['from']['name'])->first();
                        }
                        $booking_to = null;
                        if ($booking['to'] != null) {
                            $booking_to = Place::where('name', $booking['to']['name'])->first();
                        }
                        $user = null;
                        if ($booking['user'] != null) {
                            $user = User::where('email', $booking['user']['email'])->first();
                        }
                        $creator = null;
                        if ($booking['creator'] != null) {
                            $creator = User::where('email', $booking['creator']['email'])->first();
                        }
                        $booking_id = DB::table('bookings')->insertGetId([
                            "name" => $booking['name'],
                            "phone" => $booking['phone'],
                            "passengers" => $booking['passengers'],
                            "user_id" => $user != null ? $user->id : null,
                            "queue_id" => $id,
                            'from_id' => $booking_from != null ? $booking_from->id : $from->id,
                            'to_id' => $booking_to != null ? $booking_to->id : $to->id,
                            "amount" => $booking['amount'],
                            'boarded' => $booking['boarded'],
                            'paid' => $booking['paid'],
                            "start_time" => $booking["start_time"] != null ? Carbon::parse($booking["start_time"]) : null,
                            "stop_time" => $booking["stop_time"] != null ? Carbon::parse($booking["stop_time"]) : null,
                            'created_by' => $creator != null ? $creator->id : 1,
                            'status' => $booking['status'],
                            'created_at' => Carbon::parse($booking['created_at']),
                            'updated_at' => Carbon::parse($booking['updated_at'])
                        ]);
                        if ($booking_id > 0) {
                            foreach ($booking['seats'] as $seat) {
                                $my_seat = null;
                                if ($seat['seat'] != null) {
                                    if($seat['seat']['seat'] != null){
                                        $my_seat = Seat::where('name', $seat['seat']['seat']['name'])->first();
                                    }
                                }
                                $seat_arrangement = null;
                                if ($seat['seat']!=null && $my_seat != null) {
                                    $seat_arrangement = SeatArrangement::where('name', $seat['seat']['name'])
                                        ->where('seat_id', $my_seat->id)->first();
                                }
                                if ($seat_arrangement != null) {
                                    DB::table('seat_bookings')->insert([
                                        "seat_id" => $my_seat->id,
                                        "booking_id" => $booking_id,
                                        "status" => $seat['status'],
                                        'created_at' => Carbon::parse($seat['created_at']),
                                        'updated_at' => Carbon::parse($seat['updated_at'])
                                    ]);
                                }
                            }

                            foreach ($booking['mpesa_booking_callbacks'] as $callback) {
                                DB::table("mpesa_booking_callbacks")->insert([
                                    "transid" => $callback['transid'],
                                    "name" => $callback['name'],
                                    "amount" => $callback['amount'],
                                    "points" => $callback['points'],
                                    "phone" => $callback['phone'],
                                    "transdate" => Carbon::parse($callback['transdate']),
                                    "booking_id" => $booking_id,
                                    "callback" => $callback['callback'],
                                    "redeemed" => $callback['redeemed'],
                                    'created_at' => Carbon::parse($callback['created_at']),
                                    'updated_at' => Carbon::parse($callback['updated_at'])
                                ]);
                            }
                        }
                    }
                }
            }


        }
    }
}
