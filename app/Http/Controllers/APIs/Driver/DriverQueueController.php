<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Driver;

use App\Http\Controllers\Controller;
use App\Http\Resources\QueueResource;
use App\Models\Queue;
use App\Models\QueuePlace;
use App\Models\QueueStatus;
use App\Models\Route;
use App\Models\RouteStage;
use App\Models\Terminus;
use App\Models\VehicleUser;
use App\Services\Fares\FareResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * @group Driver — queue & trip lifecycle
 *
 * The driver-facing counterpart to the dispatcher's queues/add. Everything the
 * dispatcher form supplies from the client — the vehicle, the fare, the queue
 * status — is here derived server-side from the authenticated driver's active
 * assignment, so a driver can only ever queue THEIR vehicle at the SACCO's own
 * price. Mirrors the C# Queue/join, Queue/exit and Trips/start-trip flow.
 */
class DriverQueueController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Join a queue
     *
     * The driver queues the vehicle they are assigned to today at a terminus for
     * a route. The vehicle comes from the driver's active assignment (never the
     * body), the fare from the SACCO's route pricing, and the status is Pending —
     * the client sends only where it is going. Re-joining while already queued on
     * the same route returns the existing queue.
     *
     * @authenticated
     *
     * @bodyParam terminus_id integer required The terminus the vehicle is queuing at. Example: 3
     * @bodyParam route_id integer required The route being served. Example: 5
     *
     * @response 200 {"queue": {"id": 12, "queue_number": "QN-1", "queue_status_id": 1, "amount": 120}}
     * @response 409 {"error": "This vehicle is already queued on another route."}
     * @response 422 {"error": "Terminus is not the start of this route."}
     */
    public function join(Request $request, FareResolver $fares): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'terminus_id' => 'required|integer|exists:termini,id',
            'route_id' => 'required|integer|exists:routes,id',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $assignment = $this->activeAssignment();
        if ($assignment === null) {
            return response()->json(['error' => 'You have no active vehicle assignment.'], 403);
        }
        $vehicle = $assignment->vehicle;

        $route = Route::with(['from', 'to'])->find($request->route_id);
        $terminus = Terminus::find($request->terminus_id);

        // The terminus must be the route's origin — same rule addQueue enforces.
        if ($route->from_id !== $terminus->place_id) {
            return response()->json(['error' => 'Terminus is not the start of this route.'], 422);
        }

        // Already actively queued? Re-joining the same route is idempotent; a
        // different route means the driver must exit the current queue first.
        $existing = Queue::where('vehicle_id', $vehicle->id)
            ->whereHas('queue_status', fn ($q) => $q->whereIn('status', ['Pending', 'Active']))
            ->latest('id')
            ->first();
        if ($existing !== null) {
            if ((int) $existing->route_id === (int) $route->id) {
                return response()->json(['queue' => new QueueResource($existing->load($this->relations()))]);
            }

            return response()->json(['error' => 'This vehicle is already queued on another route.'], 409);
        }

        $pending = QueueStatus::where('status', 'Pending')->first();
        if ($pending === null) {
            return response()->json(['error' => 'No pending status configured.'], 422);
        }

        // SACCO's flat route fare (segment prices are resolved per-booking later).
        $fare = $fares->resolve((int) $vehicle->sacco_id, (int) $route->id, null, null) ?? 0;

        $sequence = Queue::whereBetween('created_at', [Carbon::today(), Carbon::now()])
            ->where('terminus_id', $terminus->id)
            ->where('route_id', $route->id)
            ->count() + 1;

        $queue = new Queue();
        $queue->queue_number = 'QN-' . $sequence;
        $queue->vehicle_id = $vehicle->id;
        $queue->terminus_id = $terminus->id;
        $queue->queue_status_id = $pending->id;
        $queue->route_id = $route->id;
        $queue->user_id = auth()->id();
        $queue->amount = $fare;
        $queue->queue_type = false;      // instant (not scheduled)
        $queue->start_time = Carbon::now();
        $queue->save();

        // Materialise the pickup points along the route (the pick-as-you-go stops).
        foreach (RouteStage::where('route_id', $route->id)->pluck('id') as $stageId) {
            QueuePlace::firstOrCreate(['queue_id' => $queue->id, 'route_stage_id' => $stageId]);
        }

        return response()->json(['queue' => new QueueResource($queue->load($this->relations()))], 201);
    }

    /**
     * Exit the current queue
     *
     * Cancels the driver's own active/pending queue (they pulled out before
     * departing). A queue already Completed or Cancelled is left untouched.
     *
     * @authenticated
     *
     * @response 200 {"success": "Left the queue."}
     * @response 404 {"error": "You are not currently queued."}
     */
    public function exit(): JsonResponse
    {
        $assignment = $this->activeAssignment();
        if ($assignment === null) {
            return response()->json(['error' => 'You have no active vehicle assignment.'], 403);
        }

        $queue = $this->currentQueue((int) $assignment->vehicle_id);
        if ($queue === null) {
            return response()->json(['error' => 'You are not currently queued.'], 404);
        }

        $cancelled = QueueStatus::where('status', 'Cancelled')->first();
        if ($cancelled === null) {
            return response()->json(['error' => 'No cancelled status configured.'], 422);
        }

        $queue->queue_status_id = $cancelled->id;
        $queue->save();

        return response()->json(['success' => 'Left the queue.']);
    }

    /**
     * Start the trip
     *
     * Moves the driver's Pending queue to Active and stamps the start time — the
     * server-owned transition the app calls when the vehicle departs. Idempotent:
     * a queue already Active is returned as-is.
     *
     * @authenticated
     *
     * @response 200 {"queue": {"id": 12, "queue_status_id": 2, "start_time": "2026-07-25T08:00:00Z"}}
     * @response 404 {"error": "You are not currently queued."}
     */
    public function startTrip(): JsonResponse
    {
        $assignment = $this->activeAssignment();
        if ($assignment === null) {
            return response()->json(['error' => 'You have no active vehicle assignment.'], 403);
        }

        $queue = $this->currentQueue((int) $assignment->vehicle_id);
        if ($queue === null) {
            return response()->json(['error' => 'You are not currently queued.'], 404);
        }

        if ($queue->queue_status->status === 'Active') {
            return response()->json(['queue' => new QueueResource($queue->load($this->relations()))]);
        }

        $active = QueueStatus::where('status', 'Active')->first();
        if ($active === null) {
            return response()->json(['error' => 'No active status configured.'], 422);
        }

        $queue->queue_status_id = $active->id;
        $queue->start_time = Carbon::now();
        $queue->save();

        return response()->json(['queue' => new QueueResource($queue->fresh()->load($this->relations()))]);
    }

    /** The authenticated driver's current active vehicle assignment, or null. */
    private function activeAssignment(): ?VehicleUser
    {
        return VehicleUser::with('vehicle')
            ->where('user_id', auth()->id())
            ->where('status', true)
            ->whereNull('end_date')
            ->latest('id')
            ->first();
    }

    /** The vehicle's live (Pending/Active) queue, if any. */
    private function currentQueue(int $vehicleId): ?Queue
    {
        return Queue::with('queue_status')
            ->where('vehicle_id', $vehicleId)
            ->whereHas('queue_status', fn ($q) => $q->whereIn('status', ['Pending', 'Active']))
            ->latest('id')
            ->first();
    }

    /** @return array<int, string> */
    private function relations(): array
    {
        return ['vehicle.sacco', 'route.from', 'route.to', 'terminus.place', 'queue_status', 'queue_places'];
    }
}
