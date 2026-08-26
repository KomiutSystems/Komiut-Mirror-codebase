<?php

namespace App\Http\Controllers\APIs\Dashboard\Queues;

use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Jobs\SendFCMJob;
use App\Models\Booking;
use App\Models\FirebaseToken;
use App\Models\Place;
use App\Models\Queue;
use App\Models\QueuePlace;
use App\Models\QueueStatus;
use App\Models\Route;
use App\Models\RouteStage;
use App\Models\SaccoTerminus;
use App\Models\Terminus;
use App\Models\Vehicle;
use App\Models\VehicleUser;
use App\Services\Sql\LikeSql;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class QueuesAPIController extends Controller
{
    use PaginatesResults;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function getQueues(Request $request)
    {

        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $from_date = $request->date != '' ? Carbon::parse($request->date) : Carbon::today();
        $to_date = $from_date->copy()->addDays(1);

        $vehicles = explode(',', str_replace(']', '', str_replace('[', '', $request->vehicles)));
        $all_vehicles = [];

        foreach ($vehicles as $vehicle) {
            $v = trim($vehicle);
            if ($v != '') {
                array_push($all_vehicles, trim($vehicle));
            }
        }

        $queues = Queue::with(['vehicle.sacco', 'vehicle.seat', 'route.from', 'route.to', 'queue_status', 'terminus.place', 'user', 'route.route_stages.place'])
            ->whereBetween('created_at', [$from_date, $to_date])->orderBy('position', 'ASC');
        if ($request->sacco > 0) {
            $queues = $queues->whereHas('vehicle', function ($query) use ($request) {
                $query->where('sacco_id', $request->sacco);
            });
        }

        if (count($all_vehicles) > 0) {
            $queues = $queues->whereIn('vehicle_id', $all_vehicles);
        }

        if ($request->route > 0) {
            $queues = $queues->where('route_id', $request->route);
        }
        if ($request->terminus > 0) {
            $queues = $queues->where('terminus_id', $request->terminus);
        }
        // when() wraps the ENTIRE group, not one column: an empty box would
        // otherwise make this LIKE '%%' on queue_number OR'd with EXISTS into
        // vehicles and saccos, which no index can serve.
        $queues = $queues->when(filled($request->search), fn ($builder) => $builder
            ->where(function ($query) use ($request) {
                $query->where('queue_number', LikeSql::op(), '%'.$request->search.'%');
                $query->orWhereHas('vehicle', function ($q) use ($request) {
                    $q->where('plate', LikeSql::op(), '%'.$request->search.'%');
                })->orWhereHas('vehicle.sacco', function ($q) use ($request) {
                    $q->where('name', LikeSql::op(), '%'.$request->search.'%');
                });
            }))->orderBy('created_at', 'DESC');
        $__meta = $this->pageMeta($queues, $request, 20);
        $queues = $queues->skip($offset)->take(20)->get();

        return response()->json(array_merge(['queues' => $queues], $__meta));
    }

    public function addQueue(Request $request)
    {
        // Dispatcher-only queueing: creates/edits a queue for ANY vehicle at a
        // client-supplied fare, so it stays gated on 'Add Queues' alone. Drivers
        // (who hold 'Edit Queues' for completeQueue / trips:start) must use the
        // token-scoped, server-priced queues/join instead.
        if (auth()->user()->can('Add Queues')) {
            $validator = Validator::make($request->all(), [
                'id' => 'required|integer|min:0',
                'vehicle' => 'required|integer|exists:vehicles,id',
                'terminus' => 'required|integer|exists:termini,id',
                'status' => 'required|integer|exists:queue_statuses,id',
                'route' => 'required|integer|exists:routes,id',
                'choice' => 'required|integer|min:0|max:1',
                'schedule_time' => 'required_if:choice,1',
                'amount' => 'required|integer|min:1',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }

            // `exists:vehicles,id` proves the bus exists, never that it is ours.
            // Queue is sacco-scoped through its vehicle, so a foreign vehicle id
            // could not be READ back — but nothing stopped it being WRITTEN, and
            // the row carries a caller-chosen `amount`. That is a phantom priced
            // trip against someone else's bus.
            //
            // Resolved HERE, before the duplicate-queue checks below, so those
            // run against the same vehicle this request is allowed to touch.
            $vehicle = Vehicle::find((int) $request->vehicle);
            if ($vehicle === null) {
                return response()->json(['error' => 'That vehicle is not in your SACCO.'], 404);
            }

            if ($request->choice == 1) {
                $now = Carbon::now('Africa/Nairobi');
                $schedule_time = Carbon::parse($request->schedule_time);
                if ($now > $schedule_time) {
                    // 422, not a bare json(): response()->json() with no status
                    // argument returns 200, so the dashboard read this refusal as a
                    // SUCCESS, showed nothing, and the queue was silently not created.
                    return response()->json([
                        'message' => 'That departure time has already passed. Pick a time later than now.',
                        'error' => 'That departure time has already passed. Pick a time later than now.',
                    ], 422);
                }
            }
            $route = Route::with(['from', 'to'])->where('id', $request->route)->first();
            $terminus = Terminus::where('id', $request->terminus)->first();
            if ($route->from_id != $terminus->place_id) {
                return response()->json(['error' => 'Terminus has a different place from route'], 401);
            }
            if (
                Queue::where('route_id', $request->route)-> /* where('queue_status_id', $request->status)-> */ whereHas(
                    'queue_status',
                    function ($query) {
                        $query->whereIn('status', ['Pending', 'Active']);
                    }
                )->where('vehicle_id', $vehicle->id)->where('id', '<>', $request->id)->count() > 0
            ) {
                $queueStatus = QueueStatus::where('status', 'Completed')->first();
                if ($queueStatus != null) {
                    Queue::where('route_id', $request->route)-> /* where('queue_status_id', $request->status)-> */ whereHas(
                        'queue_status',
                        function ($query) {
                            $query->whereIn('status', ['Pending', 'Active']);
                        }
                    )->where('vehicle_id', $vehicle->id)->where('id', '<>', $request->id)
                        ->update(['queue_status_id' => $queueStatus->id, 'updated_at' => Carbon::now()]);
                } else {
                    return response()->json(['error' => 'Vehicle already queued'], 401);
                }
            }
            $queue = new Queue;
            if ($request->id > 0) {
                $queue = Queue::findOrFail($request->id);
            }

            $queue->vehicle_id = $vehicle->id;
            $queue->terminus_id = $request->terminus;
            $queue->route_id = $request->route;
            $queue->queue_status_id = $request->status;
            if ($request->choice == 1) {
                $queue->schedule_time = Carbon::parse($request->schedule_time);
                $queue->start_time = Carbon::parse($request->schedule_time);
            } else {
                $queue->start_time = Carbon::now();
            }
            $queue->queue_type = $request->choice;
            $queue->amount = $request->amount;
            $queue->user_id = Auth::user()->id;

            // A NEW queue gets its integer FIFO slot assigned under a lock so two
            // dispatchers racing at the same terminus+route can never collide on a
            // position; the unique (terminus, route, day, position) index is the
            // backstop. queue_number mirrors it for display. Edits keep their slot.
            $saved = DB::transaction(function () use ($queue, $request) {
                if ((int) $request->id === 0) {
                    $position = $this->nextPosition((int) $request->terminus, (int) $request->route);
                    $queue->position = $position;
                    $queue->queue_number = 'QN-'.$position;
                }

                return $queue->save();
            });

            if ($saved) {
                $stages = RouteStage::where('route_id', $request->route)->get();
                foreach ($stages as $stage) {
                    if (QueuePlace::where('queue_id', $queue->id)->where('route_stage_id', $stage->id)->count() == 0) {
                        $queuePlace = new QueuePlace;
                        $queuePlace->queue_id = $queue->id;
                        $queuePlace->route_stage_id = $stage->id;
                        $queuePlace->save();
                    }
                }
                /*$tokens = FirebaseToken::whereHas('user.vehicle_users', function($query) use($request){
                    $query->where('vehicle_id', $request->vehicle);
                })->pluck('firebase_token');*/
                $tokens = FirebaseToken::whereHas('user', function ($userQuery) use ($request) {
                    $userQuery->whereHas('vehicle_users', function ($q) use ($request) {
                        $q->where('vehicle_id', $request->vehicle);
                    })->whereHas('permissions', function ($q) {
                        $q->where('name', 'Get Queue Notifications'); // 👈 check specific permission name
                    });
                })->pluck('firebase_token');
                $vehicle = Vehicle::find($request->vehicle);
                $title = $vehicle->plate.' Queued';
                $message = $vehicle->plate.' queued for '.$route->from->name.' to '.$route->to->name;
                foreach ($tokens as $token) {
                    dispatch(new SendFCMJob($token, $title, $message, 'queues_screen', 0));
                }

                return response()->json(['success' => 'Queue updated successfully!']);
            } else {
                return response()->json(['error' => 'Unable to update queue'], 401);
            }
        } else {
            return response()->json(['error' => 'You do not have permissione to Add/Edit Queues'], 401);
        }
    }

    /**
     * May this caller see the inside of this trip?
     *
     * `View Queues` is the wrong question on its own, and it was the only one
     * being asked. The production Driver AND Conductor roles both hold it, so
     * every one of NICCO's 227 users could read the passenger list — names,
     * phone numbers, pickups — of any of its other 179 buses. Verified live: a
     * driver crewing vehicle 886 read the manifest of a bus they have never
     * been assigned to. Only SaccoScope stood in the way, and it is not meant
     * to be the last line here.
     *
     * A trip is visible to the crew ON it, the vehicle's owner, or a SACCO
     * office role that manages trips across the fleet. Same shape as
     * TripManifestController::crews(), plus the dispatcher case that controller
     * does not need.
     */
    private function maySeeQueue(?Queue $queue): bool
    {
        if ($queue === null || $queue->vehicle === null) {
            return false;
        }

        // The office: dispatchers and managers legitimately watch every trip.
        // `Edit Queues` is the create/dispatch permission this same controller
        // already gates queue writes on — but conductors hold it in production,
        // so it cannot stand alone either. `View Passengers` is the one that
        // actually means "may read passenger records across the SACCO".
        if (auth()->user()->can('View Passengers')) {
            return true;
        }

        $userId = auth()->id();

        return (int) $queue->vehicle->user_id === (int) $userId
            || VehicleUser::where('vehicle_id', $queue->vehicle_id)
                ->where('user_id', $userId)
                ->where('status', true)
                ->exists();
    }

    public function getQueue(Request $request)
    {
        $queue = Queue::where('id', $request->id)->with(['vehicle.seat', 'vehicle.sacco', 'route.from', 'route.to', 'queue_status', 'terminus.place'])->first();
        if ($queue == null) {
            return response()->json(['error' => 'Invalid queue ID'], 401);
        }
        if (! $this->maySeeQueue($queue)) {
            return response()->json(['error' => 'You do not crew this vehicle.'], 403);
        }
        $from = Place::where('name', $request->from)->first();
        $to = Place::where('name', $request->to)->first();

        return response()->json(['queue' => $queue, 'from' => $from, 'to' => $to]);
    }

    public function getQueueBookings(Request $request)
    {
        $queue = Queue::where('id', $request->id)->with(['vehicle.seat.seat_arrangements', 'vehicle.sacco', 'route.from', 'route.to', 'queue_status', 'terminus.place', 'queue_places.route_stage.place'])->first();
        if ($queue == null) {
            return response()->json(['error' => 'Invalid queue ID'], 401);
        }
        if (! $this->maySeeQueue($queue)) {
            return response()->json(['error' => 'You do not crew this vehicle.'], 403);
        }
        $bookings = Booking::with([
            'from',
            'to',
            'creator',
            'queue.vehicle.sacco',
            'queue.vehicle.seat',
            'queue.route.from',
            'queue.route.to',
            'queue.terminus.place',
            'queue.queue_status',
            'seats.seat',
        ])
            ->where('queue_id', $queue->id);

        $bookings = $bookings->where(function ($query) use ($request) {
            $query->whereHas('queue.vehicle', function ($query) use ($request) {
                $query->where('plate', LikeSql::op(), '%'.$request->search.'%');
            })->orWhere('name', LikeSql::op(), '%'.$request->search.'%')
                ->orWhere('phone', LikeSql::op(), '%'.$request->search.'%');
        });
        $bookings = $bookings
            ->orderBy('created_at', 'DESC')->get();

        return response()->json(['queue' => $queue, 'bookings' => $bookings]);
    }

    public function getQueuesPlaces(Request $request)
    {
        $vehicleIds = VehicleUser::where('user_id', Auth::user()->id)->where('status', true)->pluck('vehicle_id');
        $queues = Queue::whereIn('vehicle_id', $vehicleIds)->whereIn('queue_status_id', QueueStatus::whereIn('status', ['Pending', 'Active'])->pluck('id'))
            ->with('queue_places.route_stage.place')->get();

        return response()->json(['queues' => $queues]);
    }

    public function getGeofence()
    {
        $termini = SaccoTerminus::with('terminus.place')->where('sacco_id', Auth::user()->sacco_id)->get();

        $queue = Queue::with('queue_places.route_stage.place')->whereHas('queue_status', function ($query) {
            $query->whereIn('status', ['Active', 'Pending']);
        })->whereHas('vehicle.vehicle_user', function ($query) {
            $query->where('user_id', Auth::user()->id)->where('status', true);
        })->first();
        $vehicles = Vehicle::with(['sacco', 'seat'])->whereHas('vehicle_user', function ($query) {
            $query->where('user_id', Auth::user()->id)->where('status', true);
        })->get();

        return response()->json(['termini' => $termini, 'queue' => $queue, 'vehicles' => $vehicles]);
    }

    public function completeQueue(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|integer|exists:queues,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }
        $queue = Queue::find($request->id);
        $queueStatus = QueueStatus::where('status', 'Completed')->first();
        if ($queueStatus == null) {
            return response()->json(['error' => 'No completed status found!'], 401);
        }
        $queue->queue_status_id = $queueStatus->id;
        if ($queue->save()) {
            return response()->json(['success' => 'Queue updated successfully!']);
        } else {
            return response()->json(['error' => 'Unable to update queue'], 401);
        }
    }

    /**
     * The next integer FIFO slot for a (terminus, route) today.
     *
     * Positions must be globally distinct within the slot group, so the read
     * ignores the SACCO scope (the unique index is (terminus, route, day,
     * position) with no sacco). On PostgreSQL a transaction-scoped advisory lock
     * keyed on the group serialises the read-max-then-insert without locking the
     * table or blocking other terminus+route pairs; the unique index is the final
     * backstop. Off PostgreSQL, FOR UPDATE on the group's rows takes its place
     * (FOR UPDATE cannot be combined with an aggregate, so the max is taken in
     * PHP). Call inside a transaction — see addQueue().
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
}
