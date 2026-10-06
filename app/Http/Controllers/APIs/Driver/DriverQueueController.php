<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Driver;

use App\Http\Controllers\Controller;
use App\Http\Resources\DriverBookingResource;
use App\Http\Resources\QueueResource;
use App\Models\Booking;
use App\Services\Loyalty\BookingSettlement;
use App\Models\Queue;
use App\Models\QueueStatus;
use App\Models\SaccoTerminus;
use App\Models\Terminus;
use App\Models\VehicleUser;
use App\Services\Queues\StageLine;
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
     * The driver puts the vehicle they are assigned to today in the line at one
     * of their SACCO's stages. A STAGE, NOT A ROUTE, decided 2026-10-06: joining
     * a stage's line says "I am waiting here", and it does not make the bus
     * bookable. Going live -- choosing the route A - B the bus is running -- is
     * what offers it to passengers, and it is a separate act with a separate
     * row (see App\Services\Booking\LiveRun). Neither creates, reuses nor
     * blocks the other.
     *
     * It used to take a route too, and the app derived one from the stage
     * tapped: KDN 458N joined at Ambassadeur after a Nairobi CBD - Thika run and
     * was put on Ambassadeur - Alsops, the first route out of that stage. A
     * `route_id` an older app still sends is accepted and ignored.
     *
     * The vehicle comes from the driver's active assignment (never the body)
     * and the status is Pending. Re-joining the stage the bus is already in
     * returns the existing place.
     *
     * @authenticated
     *
     * @bodyParam terminus_id integer required The stage the vehicle is queuing at. Example: 3
     *
     * @response 201 {"queue": {"id": 12, "kind": "stage", "queue_number": "QN-1", "position": 1, "queue_status_id": 1, "route_id": null}}
     * @response 409 {"error": "This vehicle is already in the queue at another stage. Leave it first."}
     * @response 422 {"error": "This terminus is not assigned to your SACCO."}
     */
    public function join(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'terminus_id' => 'required|integer|exists:termini,id',
            // Older apps still send one. Validated for shape, never used.
            'route_id' => 'sometimes|nullable|integer',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $assignment = $this->activeAssignment();
        if ($assignment === null) {
            return response()->json(['error' => 'You have no active vehicle assignment.'], 403);
        }
        $vehicle = $assignment->vehicle;

        $terminus = Terminus::find($request->terminus_id);
        if ($terminus === null) {
            return response()->json(['error' => 'That terminus does not exist.'], 422);
        }

        // A stage assigned to the SACCO (sacco_termini), the same table the
        // driver's terminus picker (AvailableTermini) reads.
        if (! $this->saccoHasTerminus((int) $vehicle->sacco_id, (int) $terminus->id)) {
            return response()->json(['error' => 'This terminus is not assigned to your SACCO.'], 422);
        }

        // Already in a line? The same stage is idempotent; another stage means
        // leaving this one first -- a bus is in one place at a time. A live run
        // is not a line and does not count here.
        $existing = $this->currentQueue((int) $vehicle->id);
        if ($existing !== null) {
            if ((int) $existing->terminus_id === (int) $terminus->id) {
                return response()->json(['queue' => new QueueResource($existing->load($this->relations()))]);
            }

            return response()->json(['error' => 'This vehicle is already in the queue at another stage. Leave it first.'], 409);
        }

        $pending = QueueStatus::where('status', 'Pending')->first();
        if ($pending === null) {
            return response()->json(['error' => 'No pending status configured.'], 422);
        }

        // Assign the FIFO slot and create the queue atomically: the position is
        // computed under a lock and the row inserted before the lock releases, so
        // two drivers racing for the same stage can never take one slot. One
        // line per stage per day, whatever route each bus goes on to run.
        $queue = DB::transaction(function () use ($vehicle, $terminus, $pending) {
            $position = app(StageLine::class)->takeSlot((int) $terminus->id, null);

            $queue = new Queue;
            $queue->kind = Queue::KIND_STAGE;
            $queue->position = $position;
            $queue->queue_number = 'QN-'.$position;
            $queue->vehicle_id = $vehicle->id;
            $queue->terminus_id = $terminus->id;
            $queue->queue_status_id = $pending->id;
            $queue->route_id = null;
            $queue->user_id = auth()->id();
            // No route, so no fare: a place in a line is not sold. The fare is
            // priced on the live run, per booking.
            $queue->amount = 0;
            $queue->queue_type = false;      // instant (not scheduled)
            $queue->start_time = Carbon::now();
            $queue->save();

            return $queue;
        });

        return response()->json(['queue' => new QueueResource($queue->load($this->relations()))], 201);
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
     * Exit the current queue
     *
     * Cancels the driver's own active/pending queue (they pulled out before
     * departing). A queue already Completed or Cancelled is left untouched.
     *
     * An ACTIVE queue with paid, unmarked passengers cannot be exited. This is
     * the same rule driver/trip/end enforces, and it has to hold here too or
     * the guard there is decoration: Cancelled is a terminal status, so once a
     * queue is Cancelled currentQueue() (Pending/Active only) never resolves it
     * again and no driver path -- board, no-show, end -- can reach its bookings.
     * Exiting a departed trip left every `confirmed` passenger exactly there
     * forever: money kept, no refund, no notification. Board or no-show them
     * first, or end the trip.
     *
     * @authenticated
     *
     * @response 200 {"success": "Left the queue."}
     * @response 404 {"error": "You are not currently queued."}
     * @response 409 {"error": "2 paid passengers have not been marked. Board or no-show them, or end the trip."}
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

        if ($queue->queue_status->status === 'Active') {
            $confirmed = Booking::where('queue_id', $queue->id)->statusIs('confirmed')->count();
            if ($confirmed > 0) {
                return response()->json([
                    'error' => sprintf(
                        '%d paid passenger%s %s not been marked. Board or no-show them, or end the trip.',
                        $confirmed, $confirmed === 1 ? '' : 's', $confirmed === 1 ? 'has' : 'have',
                    ),
                ], 409);
            }
        }

        $cancelled = QueueStatus::where('status', 'Cancelled')->first();
        if ($cancelled === null) {
            return response()->json(['error' => 'No cancelled status configured.'], 422);
        }

        $queue->queue_status_id = $cancelled->id;
        $queue->save();

        app(StageLine::class)->release($queue);

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

        // departed_at, NOT start_time. Departing used to overwrite start_time
        // with now, which destroyed the only record of when this matatu joined
        // the line -- so "how long did it wait at the stage" became
        // unanswerable the moment it pulled out. start_time now stays what it
        // was (the join, or the scheduled departure for a scheduled queue) and
        // departed_at records when it actually left.
        $queue->queue_status_id = $active->id;
        $queue->departed_at = Carbon::now();
        $queue->save();

        // Departing IS leaving the line. The slot goes back and everyone behind
        // moves up, which is the whole point of a queue at a stage — and it has
        // to happen here rather than at trip end, because the bus is gone from
        // the terminus the moment it pulls out.
        app(StageLine::class)->release($queue);

        return response()->json(['queue' => new QueueResource($queue->fresh()->load($this->relations()))]);
    }

    /**
     * Bookings on the current trip
     *
     * The passengers booked on the route the driver is live on, in the
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

        // Passengers book the route the bus is live on, never a stage queue.
        // A stage queue from before 2026-10-06 may still carry some, so it is
        // the fallback while one is open.
        $queue = $this->liveRun((int) $assignment->vehicle_id)
            ?? $this->currentQueue((int) $assignment->vehicle_id);
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

        // The conductor could not tell a points fare from an M-Pesa one -- the
        // list said PAID and a KES figure for both, and a passenger saying "I
        // paid with points" could not be checked against anything.
        BookingSettlement::annotate($bookings);

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

    /**
     * The vehicle's place in a stage's line (Pending, or Active once departed
     * and not yet ended), if any. Never its live run.
     */
    private function currentQueue(int $vehicleId): ?Queue
    {
        return Queue::with('queue_status')
            ->stage()
            ->where('vehicle_id', $vehicleId)
            ->whereHas('queue_status', fn ($q) => $q->whereIn('status', ['Pending', 'Active']))
            ->latest('id')
            ->first();
    }

    /** The route this vehicle is live on, if any. */
    private function liveRun(int $vehicleId): ?Queue
    {
        return Queue::live()
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
