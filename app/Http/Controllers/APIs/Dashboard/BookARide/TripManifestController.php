<?php

namespace App\Http\Controllers\APIs\Dashboard\BookARide;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Queue;
use App\Models\VehicleUser;
use Illuminate\Http\Request;

/**
 * @group Live tracking
 */
class TripManifestController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Trip manifest (driver)
     *
     * Every booking on this trip with its pickup and dropoff point and seats, so
     * the driver knows exactly who to pick up where and drop where — the passenger
     * is never asked, it's on the booking. Also rolls up the distinct pickup and
     * dropoff points with passenger counts. Restricted to the vehicle's crew/owner.
     *
     * @authenticated
     *
     * @urlParam id integer required The queue (trip) id. Example: 7
     *
     * @response 200 {"queue_id": 7, "bookings": [{"id": 41, "name": "Wanjiku", "paid": true, "seats": [4], "pickup": {"id": 12, "name": "Ruiru"}, "dropoff": {"id": 18, "name": "Thika"}}], "pickups": [{"place_id": 12, "name": "Ruiru", "passengers": 1}], "dropoffs": [{"place_id": 18, "name": "Thika", "passengers": 1}]}
     * @response 403 {"error": "You do not crew this vehicle."}
     */
    public function manifest(Request $request, int $id)
    {
        $queue = Queue::with('vehicle')->find($id);
        if ($queue === null) {
            return response()->json(['error' => 'Trip not found'], 404);
        }
        if (! $this->crews($queue)) {
            return response()->json(['error' => 'You do not crew this vehicle.'], 403);
        }

        $bookings = Booking::withoutGlobalScopes()
            ->where('queue_id', $id)
            ->where('status', true)
            ->with(['from', 'to', 'seats', 'user'])
            ->orderBy('created_at')
            ->get();

        $manifest = $bookings->map(fn (Booking $b) => [
            'id' => $b->id,
            'name' => $b->name,
            'phone' => $b->phone,
            'passengers' => $b->passengers,
            'paid' => (bool) $b->paid,
            'boarded' => (bool) $b->boarded,
            'seats' => $b->seats->pluck('seat_id')->values(),
            'pickup' => $b->from ? ['id' => $b->from->id, 'name' => $b->from->name] : null,
            'dropoff' => $b->to ? ['id' => $b->to->id, 'name' => $b->to->name] : null,
        ]);

        $pickups = $bookings->groupBy('from_id')->map(fn ($group) => [
            'place_id' => $group->first()->from_id,
            'name' => optional($group->first()->from)->name,
            'passengers' => (int) $group->sum('passengers'),
        ])->values();

        $dropoffs = $bookings->groupBy('to_id')->map(fn ($group) => [
            'place_id' => $group->first()->to_id,
            'name' => optional($group->first()->to)->name,
            'passengers' => (int) $group->sum('passengers'),
        ])->values();

        return response()->json([
            'queue_id' => $id,
            'bookings' => $manifest,
            'pickups' => $pickups,
            'dropoffs' => $dropoffs,
        ]);
    }

    private function crews(?Queue $queue): bool
    {
        if ($queue === null || $queue->vehicle === null) {
            return false;
        }
        $userId = auth()->id();

        return (int) $queue->vehicle->user_id === (int) $userId
            || VehicleUser::where('vehicle_id', $queue->vehicle_id)
                ->where('user_id', $userId)
                ->where('status', true)
                ->exists();
    }
}
