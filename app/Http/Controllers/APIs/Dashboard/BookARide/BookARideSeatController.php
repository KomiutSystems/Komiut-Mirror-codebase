<?php
namespace App\Http\Controllers\APIs\Dashboard\BookARide;

use App\Http\Controllers\Controller;
use App\Models\SeatBooking;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BookARideSeatController extends Controller
{
    public function __construct(){
        $this->middleware('auth:api');
    }
    public function getVehicleSeats(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'bus_id' => 'required|min:1|integer',
            'id' => 'required|min:1',
            'booking_id' => 'integer|nullable|min:1'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }
        $seats = Vehicle::where('id', $request->bus_id)->with('seat.seat_arrangements')
            ->first();

        $booked = SeatBooking::select('seat_id as seatId')->whereHas('booking.queue', function ($query) use ($request) {
            $query->where('id', $request->id);
        });
        if ($request->booking_id > 0) {
            $booked = $booked->where('booking_id', '<>', $request->booking_id);
        }
        $booked = $booked->get();
        return response()->json(['seats' => $seats, 'booked' => $booked]);
    }
}
