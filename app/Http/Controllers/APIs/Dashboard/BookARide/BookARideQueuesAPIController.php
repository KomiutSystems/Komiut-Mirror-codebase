<?php

namespace App\Http\Controllers\APIs\Dashboard\BookARide;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Services\SendFCMMessageController;
use App\Jobs\SendFCMJob;
use App\Jobs\SendSMSJob;
use App\Models\Booking;
use App\Models\FirebaseToken;
use App\Models\Place;
use App\Models\Queue;
use App\Models\SeatArrangement;
use App\Models\SeatBooking;
use App\Models\VehicleUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class BookARideQueuesAPIController extends Controller
{
    
    public function __construct(){
        $this->middleware('auth:api');
    }

    public function getQueues(Request $request){
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $queues = Queue::with(['terminus', 'queue_status','vehicle.sacco', 'vehicle.seat','route.route_stages.place', 'route.from', 'route.to', 'terminus.place'])
        /*
        $routes = Route::with(['from', 'to', 'route_stages.place','queues.queue_status'=>function($query){
            $query->where('status', 'Active')->orWhere('status', 'Pending');
        }])->where('name', 'LIKE', '%'.$request->search.'%')
        ->orWhereHas('from', function($query) use($request){
            $query->where('name', 'LIKE', '%'.$request->search.'%');
        })->orWhereHas('to', function($query) use($request){
            $query->where('name', 'LIKE', '%'.$request->search.'%');
        })*/->skip($offset)->take(20)
        ->orderBy('created_at', 'DESC')->get();
        return response()->json(['queues'=>$queues]);
    }public function addBooking(Request $request)
    {
            $validator = Validator::make($request->all(), [
                'id' => 'required|integer|min:1|exists:queues,id',//queue id
                'booking_id'=>'integer|min:1|nullable',
                'seats' => 'required|string',
                'name' => 'required|string',
                'phone' => 'required|digits_between:10,12',
                'amount' => 'required|numeric|min:0',
                'fromId' => 'integer|min:0|nullable',
                'toId' => 'integer|min:0|nullable',
            ]);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }
            $phone = $request->phone;
            if(strlen($request->phone)<12){
                $phone = '254'.intval($request->phone);
            }
            //seats settings
            $seats = explode(',', str_replace(']', '', str_replace('[', '', $request->seats)));
            $seat_ok = true;
            $seat_message = "";
            $all_seats = [];

            foreach ($seats as $seat) {
                array_push($all_seats, trim($seat));
                $seatArrangement = SeatArrangement::where('id', trim($seat))->first();
                $bookedSeats = SeatBooking::whereHas('booking', function($query) use($request){
                    $query->where('queue_id', $request->id);
                })->where('seat_id', $seatArrangement->id);
                if($request->booking_id > 0){
                    $bookedSeats = $bookedSeats->where('booking_id', '<>', $request->booking_id);
                } 
                if ($bookedSeats->count() > 0) {
                    $seat_ok = false;
                }
                $seat_message .= $seatArrangement->name.",";
            }
            if ($seat_ok) {
                $from = $request->fromId;
                $to = $request->toId;

                $queue = Queue::with('vehicle.sacco','route.from', 'route.to')->where('id', $request->id)->first();
                if(intval($from) <= 0){
                    $from = $queue->route->from_id;
                }
                if(intval($to) <= 0){
                    $to = $queue->route->to_id;
                }
                //return response()->json(['from'=>$from, 'to'=>$to]);
                $booking = new Booking;
                if($request->booking_id > 0){
                    $booking = Booking::find($request->booking_id);
                }
                $booking->name = $request->name;
                $booking->phone = $phone;
                $booking->passengers = count($seats);
                $booking->user_id = auth('api')->user()->id;
                $booking->queue_id = $request->id;
                $booking->from_id = $from;
                $booking->to_id = $to; 
                $booking->amount = $request->amount;
                $booking->created_by = auth('api')->user()->id;
                if ($booking->save()) {
                    SeatBooking::where('booking_id', $booking->id)->whereNotIn('seat_id', $all_seats)->delete();
                    foreach ($seats as $seat) {
                        $seatArrangement = SeatArrangement::where('id', trim($seat))->first();
                        if ($seatArrangement != null) {
                            if(SeatBooking::where('booking_id', $booking->id)->where('seat_id', $seatArrangement->id)
                            ->whereHas('booking', function($query) use($request){
                                $query->where('queue_id', $request->id);
                            })->count() ==0){
                                $seatBooking = new SeatBooking;
                                $seatBooking->seat_id = $seatArrangement->id;
                                $seatBooking->booking_id = $booking->id;
                                $seatBooking->save();
                            }
                        }
                    }
                    $pickup = Place::where('id', $from)->first();
                    $dropoff = Place::where('id', $to)->first();
                    $departure = $pickup != null?$pickup->name:$queue->route->from->name;
                    $destination = $dropoff != null?$dropoff->name:$queue->route->to->name;

                    //send message
                    $message = "Hi $request->name. You have successfully booked for ".$queue->vehicle->plate." from $departure to $destination with seat(s): ";
                    $message .= substr($seat_message, 0, -1).". You are travelling with ".strtoupper($queue->vehicle->sacco != null?$queue->vehicle->sacco->name:'NA').". ";
                    $message .= "Pickup point is $departure. Make payments now to secure booking!";
                    dispatch(new SendSMSJob($phone, $message));

                    $tokens = FirebaseToken::whereHas('user.vehicle_users', function($query) use($queue){
                        $query->where('vehicle_id', $queue->vehicle->id)->where('status', true);
                    })->orWhere('user_id', Auth::user()->id)->pluck('firebase_token');
                    if(!empty($tokens)){
                        //send FCM message
                        $message = \Auth::user()->name . " has booked ".$queue->vehicle->plate." from $departure  to $destination. Book is awaiting payments!";
                        $title = "Booking from ".Auth::user()->firstname;
                        dispatch(new SendFCMJob($tokens, $title, $message));
                    }
                    return response()->json(['success' => 'Booking successful!', "booking_id" => $booking->id]);
                } else {
                    return response()->json(['error' => 'Unable to complete booking!'], 400);
                }
            } else {
                return response()->json(['error' => 'Seat '.substr($seat_message, 0, -1).' already booked. try a different seat!'], 400);
            }
    }
}
