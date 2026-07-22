<?php
namespace App\Http\Controllers\APIs\Dashboard\BookARide;

use App\Http\Controllers\Controller;
use App\Models\Queue;
use App\Models\Vehicle;
use App\Services\Booking\SegmentSeatAvailability;
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
     * Returns the vehicle's seat layout plus the seats taken for the requested
     * pickup→dropoff. Availability is segment-aware (pick-as-you-go): pass
     * from_id/to_id to see seats free for just that span; omit them to treat it
     * as the whole trip. Uses the exact rule addBooking enforces, so a seat shown
     * free will not be rejected at confirm.
     *
     * @authenticated
     *
     * @queryParam bus_id integer required The vehicle id. Example: 3
     * @queryParam id integer required The queue (trip) id. Example: 7
     * @queryParam from_id integer Pickup stop (place) id. Example: 12
     * @queryParam to_id integer Dropoff stop (place) id. Example: 18
     * @queryParam booking_id integer Exclude this booking's own held seats (when amending). Example: 41
     *
     * @response 200 {"seats": {"seat": {"seat_arrangements": []}}, "booked": [{"seatId": 4}]}
     */
    public function getVehicleSeats(Request $request, SegmentSeatAvailability $seatAvailability)
    {
        $validator = Validator::make($request->all(), [
            'bus_id' => 'required|min:1|integer',
            'id' => 'required|min:1|integer|exists:queues,id',
            'from_id' => 'integer|nullable|min:1',
            'to_id' => 'integer|nullable|min:1',
            'booking_id' => 'integer|nullable|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $seats = Vehicle::where('id', $request->bus_id)->with('seat.seat_arrangements')->first();
        $queue = Queue::find($request->id);

        $occupied = $seatAvailability->occupiedSeatIds(
            $queue,
            $request->filled('from_id') ? (int) $request->from_id : null,
            $request->filled('to_id') ? (int) $request->to_id : null,
            $request->booking_id > 0 ? (int) $request->booking_id : null,
        );

        $booked = collect($occupied)->map(fn (int $id) => ['seatId' => $id])->values();

        return response()->json(['seats' => $seats, 'booked' => $booked]);
    }
}
