<?php
namespace App\Http\Controllers\APIs\Dashboard\BookARide;

use App\Http\Controllers\Controller;
use App\Models\SeatBooking;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * @group Book a ride
 */
class BookARideSeatController extends Controller
{
    public function __construct(){
        $this->middleware('auth:sanctum');
    }

    /**
     * Get the seat map for a trip
     *
     * Returns the vehicle's seat layout plus the seats already taken on this
     * queue. `booked` uses the exact same occupancy rule the booking check
     * enforces, so a free seat here will not be rejected at confirm.
     *
     * @authenticated
     *
     * @queryParam bus_id integer required The vehicle id. Example: 3
     * @queryParam id integer required The queue (trip) id. Example: 7
     * @queryParam booking_id integer Exclude this booking's own held seats (when amending). Example: 41
     *
     * @response 200 {"seats": {"seat": {"seat_arrangements": []}}, "booked": [{"seatId": 4}]}
     */
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

        // Same occupancy definition addBooking enforces, so a seat shown free
        // here can't be rejected at "confirm" (and expired holds read as free).
        $booked = SeatBooking::occupiedForQueue((int) $request->id)
            ->when($request->booking_id > 0, fn ($q) => $q->where('booking_id', '<>', $request->booking_id))
            ->get(['seat_id as seatId']);

        return response()->json(['seats' => $seats, 'booked' => $booked]);
    }
}
