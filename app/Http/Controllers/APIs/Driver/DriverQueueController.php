<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Driver;

use App\Http\Controllers\Controller;
use App\Http\Resources\DriverBookingResource;
use App\Http\Resources\QueueResource;
use App\Models\Booking;
use App\Models\Queue;
use App\Models\QueuePlace;
use App\Models\QueueStatus;
use App\Models\Route;
use App\Models\RouteStage;
use App\Models\SaccoRoute;
use App\Models\SaccoTerminus;
use App\Models\Terminus;
use App\Models\VehicleUser;
use App\Services\Fares\FareResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

        // The route must be one this vehicle's SACCO actually runs (a sacco_routes
        // row). Without this a driver could queue on ANY brand route; the fare
        // resolver would then find no price and fall back to 0, and the SACCO
        // would run a free trip. Refuse rather than create that fare-0 queue.
        if (! $this->saccoRunsRoute((int) $vehicle->sacco_id, (int) $route->id)) {
            return response()->json(['error' => 'This route is not offered by your SACCO.'], 422);
        }

        // ...and the terminus must be one assigned to the SACCO (sacco_termini),
        // the same table the driver's terminus picker (AvailableTermini) reads.
        if (! $this->saccoHasTerminus((int) $vehicle->sacco_id, (int) $terminus->id)) {
            return response()->json(['error' => 'This terminus is not assigned to your SACCO.'], 422);
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
        // Guaranteed non-null by the saccoRunsRoute() check above; the ?? 0 is a
        // belt-and-braces guard, no longer the silent free-trip path it was.
        $fare = $fares->resolve((int) $vehicle->sacco_id, (int) $route->id, null, null) ?? 0;

        // Assign the FIFO slot and create the queue atomically: the position is
        // computed under a lock and the row inserted before the lock releases, so
        // two drivers racing for the same terminus+route can never take one slot.
        $queue = DB::transaction(function () use ($vehicle, $terminus, $route, $pending, $fare) {
            $position = $this->nextPosition((int) $terminus->id, (int) $route->id);

            $queue = new Queue;
            $queue->position = $position;
            $queue->queue_number = 'QN-'.$position;
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

            return $queue;
        });

        return response()->json(['queue' => new QueueResource($queue->load($this->relations()))], 201);
    }

    /** Does this SACCO run/price this route (a sacco_routes row)? */
    private function saccoRunsRoute(int $saccoId, int $routeId): bool
    {
        return SaccoRoute::withoutGlobalScopes()
            ->where('sacco_id', $saccoId)
            ->where('route_id', $routeId)
            ->where('status', true)
            ->exists();
    }

    /** Is this terminus assigned to the SACCO (a sacco_termini row)? */
    private function saccoHasTerminus(int $saccoId, int $terminusId): bool
    {
        return SaccoTerminus::withoutGlobalScopes()
            ->where('sacco_id', $saccoId)
            ->where('terminus_id', $terminusId)
            ->exists();
    }

    /**
     * The next integer FIFO slot for a (terminus, route) today.
     *
     * Positions must be globally distinct within the slot group, so the read
     * ignores the SACCO scope (the unique index is (terminus, route, day,
     * position) with no sacco). On PostgreSQL a transaction-scoped advisory lock
     * keyed on the group serialises the read-max-then-insert without locking the
     * table or blocking joins on other terminus+route pairs; the unique index is
     * the final backstop. Off PostgreSQL, FOR UPDATE on the group's rows takes
     * its place (FOR UPDATE cannot be combined with an aggregate, so the max is
     * taken in PHP). Call inside a transaction — see join().
     */
    private function nextPosition(int $terminusId, int $routeId): int
    {
        $today = Carbon::today();

        $query = Queue::withoutGlobalScopes()
            ->where('terminus_id', $terminusId)
            ->where('route_id', $routeId)
            ->whereDate('created_at', $today);

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('SELECT pg_advisory_xact_lock(?)', [
                $this->slotLockKey($terminusId, $routeId, $today->toDateString()),
            ]);

            return (int) $query->max('position') + 1;
        }

        $max = $query->lockForUpdate()->pluck('position')->max();

        return (int) $max + 1;
    }

    /** Stable 32-bit key for pg_advisory_xact_lock derived from the slot group. */
    private function slotLockKey(int $terminusId, int $routeId, string $day): int
    {
        return (int) crc32("queue-slot:{$terminusId}:{$routeId}:{$day}");
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

    /**
     * Bookings on the current trip
     *
     * The passengers booked on the driver's live (Pending/Active) queue, in the
     * shape the driver Bookings page reads: passenger name/phone, selected
     * pickup/dropoff points, and a bookingType discriminator. Empty when the
     * driver is not queued.
     *
     * @authenticated
     *
     * @response 200 {"bookings": [{"bookingId": 1, "passengerName": "Wanjiku", "passengerPhone": "2547...", "bookingType": "route", "pickup": {"id": 12, "name": "CBD"}, "dropoff": {"id": 18, "name": "Thika"}}]}
     */
    public function bookings(Request $request): JsonResponse
    {
        $assignment = $this->activeAssignment();
        if ($assignment === null) {
            return response()->json(['error' => 'You have no active vehicle assignment.'], 403);
        }

        $statuses = ['all', 'reserved', 'confirmed', 'boarded', 'failed'];

        $queue = $this->currentQueue((int) $assignment->vehicle_id);
        if ($queue === null) {
            return response()->json(['bookings' => [], 'statuses' => $statuses]);
        }

        // All statuses by default (cancelled/failed included), filterable by
        // ?booking_status — the same vocabulary DriverPortal and the dashboard use.
        // Previously this hard-filtered status=true and silently HID cancelled
        // rows, so the two "my trip bookings" endpoints disagreed.
        $bookings = Booking::with(['from', 'to', 'seats'])
            ->where('queue_id', $queue->id)
            ->statusIs($request->input('booking_status'))
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'bookings' => $bookings->map(fn (Booking $booking) => array_merge(
                (new DriverBookingResource($booking))->toArray($request),
                ['status_label' => $booking->status_label],
            )),
            'statuses' => $statuses,
        ]);
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
