<?php

namespace App\Http\Controllers\APIs\Dashboard\BookARide;

use App\Http\Controllers\Controller;
use App\Models\Queue;
use App\Models\VehicleUser;
use App\Services\Location\VehicleLocationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * @group Live tracking
 */
class VehicleLocationController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Broadcast the driver's position
     *
     * The driver app calls this every few seconds while on a trip. It updates the
     * live index and pushes a `vehicle.moved` event over Reverb on the trip's
     * private channel, so every passenger who booked this queue sees the vehicle
     * move in real time. Only the vehicle's crew/owner may broadcast for it.
     *
     * @authenticated
     *
     * @bodyParam queue_id integer required The active trip (queue) id. Example: 7
     * @bodyParam latitude number required Current latitude. Example: -1.2833
     * @bodyParam longitude number required Current longitude. Example: 36.8167
     *
     * @response 202 {"status": "broadcasting", "heading": 74}
     * @response 403 {"error": "You do not crew this vehicle."}
     */
    public function broadcastLocation(Request $request, VehicleLocationService $service)
    {
        $validator = Validator::make($request->all(), [
            'queue_id' => 'required|integer|min:1|exists:queues,id',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $queue = Queue::with('vehicle')->find($request->queue_id);
        if (! $this->crews($queue)) {
            return response()->json(['error' => 'You do not crew this vehicle.'], 403);
        }

        $location = $service->update(
            (int) $queue->vehicle_id,
            (float) $request->latitude,
            (float) $request->longitude,
            $queue,
        );

        return response()->json(['status' => 'broadcasting', 'heading' => $location->heading], 202);
    }

    /**
     * Stop broadcasting
     *
     * Called when the trip ends — the vehicle drops off the live map.
     *
     * @authenticated
     *
     * @bodyParam queue_id integer required The trip (queue) id. Example: 7
     */
    public function stopBroadcasting(Request $request, VehicleLocationService $service)
    {
        $validator = Validator::make($request->all(), [
            'queue_id' => 'required|integer|min:1|exists:queues,id',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $queue = Queue::with('vehicle')->find($request->queue_id);
        if (! $this->crews($queue)) {
            return response()->json(['error' => 'You do not crew this vehicle.'], 403);
        }

        $service->stop((int) $queue->vehicle_id);

        return response()->json(['status' => 'stopped']);
    }

    /**
     * Live vehicles near me
     *
     * Returns broadcasting vehicles within radius (km) of a point — freshest and
     * nearest first — for the "matatus approaching my stop" map. Optionally
     * constrained to a route.
     *
     * @authenticated
     *
     * @queryParam latitude number required Your latitude. Example: -1.2921
     * @queryParam longitude number required Your longitude. Example: 36.8219
     * @queryParam radius number Search radius in km (default 5). Example: 3
     * @queryParam route_id integer Only vehicles on this route. Example: 5
     *
     * @response 200 {"vehicles": [{"vehicle_id": 3, "plate": "KDA001A", "capacity": 14, "latitude": -1.29, "longitude": 36.82, "heading": 74, "distance_km": 0.4}]}
     */
    public function nearby(Request $request, VehicleLocationService $service)
    {
        $validator = Validator::make($request->all(), [
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'radius' => 'numeric|nullable|min:0.1|max:50',
            'route_id' => 'integer|nullable|min:1',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $vehicles = $service->nearby(
            (float) $request->latitude,
            (float) $request->longitude,
            $request->filled('radius') ? (float) $request->radius : 5.0,
            $request->filled('route_id') ? (int) $request->route_id : null,
        );

        return response()->json(['vehicles' => $vehicles]);
    }

    /** The authenticated user drives or owns this queue's vehicle. */
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
