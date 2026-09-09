<?php

namespace App\Http\Controllers\APIs\Dashboard\BookARide;

use App\Http\Controllers\Concerns\ResolvesDriverVehicle;
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
    use ResolvesDriverVehicle;

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
     * @bodyParam queue_id integer The active trip (queue) id. Optional -- omit it
     *   and the trip is resolved from your own open assignment. Example: 7
     * @bodyParam route_id integer The route you are running when you are NOT on a
     *   queue -- the return leg, or waiting at the stage. It is what tells a
     *   passenger which way the bus is heading, and `nearby` both returns it and
     *   filters on it. PRECEDENCE: if a queue is in play (sent, or resolved from
     *   your assignment) that queue's own route wins and this is discarded --
     *   see VehicleLocationService::update, `$queue?->route_id ?? $routeId`. So
     *   it only takes effect when there is genuinely no open queue. Example: 1973
     * @bodyParam latitude number required Current latitude. Example: -1.2833
     * @bodyParam longitude number required Current longitude. Example: 36.8167
     *
     * @response 202 {"status": "broadcasting", "heading": 74}
     * @response 400 {"errors": {"route_id": ["The selected route id is invalid."]}}
     * @response 403 {"error": "You do not crew this vehicle."}
     * @response 403 {"error": "You have no active vehicle assignment."}
     */
    public function broadcastLocation(Request $request, VehicleLocationService $service)
    {
        $validator = Validator::make($request->all(), [
            'queue_id' => 'sometimes|integer|min:1|exists:queues,id',
            'route_id' => 'sometimes|integer|min:1|exists:routes,id',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        // BEING LIVE AND BEING ON A TRIP ARE INDEPENDENT. This used to refuse
        // with 422 "You are not currently on a trip", which welded location
        // broadcasting to the queue lifecycle: a driver could not show on the
        // map while waiting at the stage, and a bus running with the app closed
        // and reopened mid-route could not start broadcasting at all. Going
        // live is a driver saying "I am here, on this route, with these seats";
        // the queue is a separate fact about the stage.
        //
        // The ASSIGNMENT is the authorisation boundary — vehicle() resolves the
        // caller's own open assignment and nothing else — so a queue is no
        // longer needed to prove anything.
        // A trip is optional CONTEXT, not a precondition. When the bus is on one
        // the ping carries the queue, so passengers who booked it keep the
        // moving pin; when it is not, the ping is still recorded and still
        // surfaces in `nearby`.
        //
        // The queue path is checked FIRST and keeps its own authorisation.
        // crews() admits the vehicle's OWNER as well as its crew, and resolving
        // through the assignment instead would have quietly withdrawn that --
        // narrowing who may broadcast is not part of decoupling it from trips.
        $queue = $this->trip($request);

        if ($queue !== null) {
            if (! $this->crews($queue)) {
                return response()->json(['error' => 'You do not crew this vehicle.'], 403);
            }

            $vehicleId = (int) $queue->vehicle_id;
        } else {
            $vehicle = $this->vehicle();
            if ($vehicle === null) {
                return $this->noAssignment();
            }

            $vehicleId = (int) $vehicle->id;
        }

        $location = $service->update(
            $vehicleId,
            (float) $request->latitude,
            (float) $request->longitude,
            $queue,
            $request->filled('route_id') ? (int) $request->route_id : null,
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
     * @bodyParam queue_id integer The trip (queue) id. Optional for a driver.
     *   Example: 7
     */
    public function stopBroadcasting(Request $request, VehicleLocationService $service)
    {
        $validator = Validator::make($request->all(), [
            'queue_id' => 'sometimes|integer|min:1|exists:queues,id',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        // Resolved from the assignment, not from a trip. Requiring one here was
        // worse than the same fault on the write path: a driver who ended their
        // trip and then tried to go offline had no live queue left to resolve,
        // so the stop was refused and the bus kept showing as broadcasting
        // until the record went stale on its own.
        $queue = $this->trip($request);

        if ($queue !== null) {
            if (! $this->crews($queue)) {
                return response()->json(['error' => 'You do not crew this vehicle.'], 403);
            }

            $vehicleId = (int) $queue->vehicle_id;
        } else {
            $vehicle = $this->vehicle();
            if ($vehicle === null) {
                return $this->noAssignment();
            }

            $vehicleId = (int) $vehicle->id;
        }

        $service->stop($vehicleId);

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
     * Every field is snake_case, like the rest of this API. `capacity`, `sacco`,
     * `route_id`, `route_name`, `queue_id` and `recorded_at` may be null.
     *
     * @response 200 {"vehicles": [{"vehicle_id": 3, "plate": "KDA001A", "capacity": 14, "sacco": "Super Metro", "route_id": 5, "route_name": "Nairobi - Thika", "queue_id": 7, "latitude": -1.29, "longitude": 36.82, "heading": 74, "distance_km": 0.4, "recorded_at": "2026-08-07T09:14:22+00:00"}]}
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
    /**
     * The trip being broadcast for.
     *
     * The driver app posts {vehicleId, latitude, longitude, routeId} every four
     * seconds and never sends queue_id, so a required queue_id 400'd every GPS
     * ping and the matatu never appeared on the live map.
     *
     * Resolving it from the caller's own assignment is also the better rule:
     * the dashboard still passes an explicit id (it broadcasts on behalf of a
     * vehicle it manages), but a driver does not have to name a queue at all,
     * and therefore cannot name someone else's. crews() still gates both paths.
     */
    private function trip(Request $request): ?Queue
    {
        if ($request->filled('queue_id')) {
            return Queue::with('vehicle')->find($request->input('queue_id'));
        }

        $vehicle = $this->vehicle();
        if ($vehicle === null) {
            return null;
        }

        return $this->currentQueue((int) $vehicle->id)?->load('vehicle');
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
