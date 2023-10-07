<?php

namespace App\Http\Controllers\APIs\Dashboard\Bookings;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Parcel;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BookingsAPIController extends Controller
{
    
    public function __construct(){
        $this->middleware('auth:api');
    }
    public function getPassengerBookings(Request $request){
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $from_date = $request->date != ""?Carbon::parse($request->date):Carbon::today();
        $to_date = $from_date->copy()->addDays(1);

        $bookings = Booking::with(['from', 'to', 'creator', 'queue.vehicle.sacco', 'queue.vehicle.seat','queue.route.from', 
        'queue.route.to', 'queue.terminus', 'queue.queue_status'])
        ->whereBetween('created_at', [$from_date, $to_date]);
        if(!auth()->user()->can('View Passengers')){
            $bookings = $bookings->where('user_id', Auth::user()->id);
        }
        if($request->sacco > 0){
            $bookings = $bookings->whereHas('queue.vehicle', function($query) use ($request){
                $query->where('sacco_id', $request->sacco);
            });
        }
        if($request->from > 0){
            $bookings = $bookings->where('from_id', $request->from);
        }
        if($request->to > 0){
            $bookings = $bookings->where('to_id', $request->to);
        }
        if($request->sacco > 0){
            $bookings = $bookings->where('sacco_id', $request->sacco);
        }
        $bookings = $bookings->where(function($query) use($request){
            $query->whereHas('queue.vehicle', function($query) use($request){
                $query->where('plate', 'LIKE', '%'.$request->search.'%');
            })->orWhere('name', 'LIKE', '%'.$request->search.'%')
            ->orWhere('phone', 'LIKE', '%'.$request->search.'%');
        });
        $bookings = $bookings->skip($offset)->take(20)
        ->orderBy('created_at', 'DESC')->get();
        return response()->json(['bookings'=>$bookings]);
    }
    public function getPassengerBooking(Request $request){

        $booking = Booking::with(['from', 'to', 'creator', 'queue.vehicle.sacco', 'queue.vehicle.seat','queue.route.from', 
        'queue.route.to', 'queue.terminus', 'queue.queue_status', 'seats.seat', 'mpesa_booking_callbacks'])->where('id', $request->id);
        if(!auth()->user()->can('View Passengers')){
            $booking = $booking->where('user_id', Auth::user()->id);
        }
        if($booking == null){
            return response()->json(['error'=>'Invalid booking id'], 401);
        }
        $booking = $booking->first();
        return response()->json(['booking'=>$booking]);
    }
    
    public function getParcels(Request $request){
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        
        $from_date = $request->date != ""?Carbon::parse($request->date):Carbon::today();
        $to_date = $from_date->copy()->addDays(1);

        $parcels = Parcel::with(['from', 'to', 'creator', 'vehicle.sacco'])
        ->whereBetween('created_at', [$from_date, $to_date]);
        $parcels = $parcels->where(function($q) use($request){
            $q->where('recipient_name', 'LIKE', '%'.$request->search.'%')
            ->orWhere('recipient_phone', 'LIKE', '%'.$request->search.'%')
            ->orWhere('recipient_idno', 'LIKE', '%'.$request->search.'%')
            ->orWhere('sender_name', 'LIKE', '%'.$request->search.'%')
            ->orWhere('sender_phone', 'LIKE', '%'.$request->search.'%')
            ->orWhere('sender_idno', 'LIKE', '%'.$request->search.'%')
            ->orWhere('name', 'LIKE', '%'.$request->search.'%');
        });
        if($request->vehicle > 0){
            $parcels = $parcels->where('vehicle_id', $request->vehicle);
        }
        if($request->sacco > 0){
            $parcels = $parcels->whereHas('vehicle', function($query) use ($request){
                $query->where('sacco_id', $request->sacco);
            });
        }
        if($request->from > 0){
            $parcels = $parcels->where('from_id', $request->from);
        }
        if($request->to > 0){
            $parcels = $parcels->where('to_id', $request->to);
        }
        $parcels = $parcels->skip($offset)->take(20)
        ->orderBy('created_at', 'DESC')->get();
        return response()->json(['parcels'=>$parcels]);
    }
}
