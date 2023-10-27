<?php

namespace App\Http\Controllers\APIs\Dashboard\Queues;

use App\Http\Controllers\Controller;
use App\Models\Booking;
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
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

class QueuesAPIController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api');
    }
    public function getQueues(Request $request)
    {

        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $from_date = $request->date != "" ? Carbon::parse($request->date) : Carbon::today();
        $to_date = $from_date->copy()->addDays(1);

        $vehicles = explode(',', str_replace(']', '', str_replace('[', '', $request->vehicles)));
        $all_vehicles = [];

        foreach ($vehicles as $vehicle) {
            $v = trim($vehicle);
            if ($v != "") {
                array_push($all_vehicles, trim($vehicle));
            }
        }

        $queues = Queue::with(['vehicle.sacco', 'vehicle.seat', 'route.from', 'route.to', 'queue_status', 'terminus.place', 'user', 'route.route_stages.place'])
            ->whereBetween('created_at', [$from_date, $to_date])->orderBy('queue_number', 'ASC');
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
            $queues = $queues->where('terminus_id', $request->terminu);
        }
        $queues = $queues->where(function ($query) use ($request) {
            $query->where('queue_number', 'LIKE', '%' . $request->search . '%');
            $query->orWhereHas('vehicle', function ($q) use ($request) {
                $q->where('plate', 'LIKE', '%' . $request->search . '%');
            })->orWhereHas('vehicle.sacco', function ($q) use ($request) {
                $q->where('name', 'LIKE', '%' . $request->search . '%');
            });
        })->orderBy('created_at', 'DESC')->skip($offset)->take(20)->get();
        return response()->json(['queues' => $queues]);
    }
    public function addQueue(Request $request)
    {
        if (auth()->user()->can('Add Queues') || auth()->user()->can('Edit Queues')) {
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
            if ($request->choice == 1) {
                $now = Carbon::now('Africa/Nairobi');
                $schedule_time = Carbon::parse($request->schedule_time);
                if ($now > $schedule_time) {
                    return response()->json(['error' => 'A future schedule time is required!']);
                }
            }
            $route = Route::where('id', $request->route)->first();
            $terminus = Terminus::where('id', $request->terminus)->first();
            if ($route->from_id != $terminus->place_id) {
                return response()->json(['error' => 'Terminus has a different place from route'], 401);
            }
            if (
                Queue::where('route_id', $request->route)-> /*where('queue_status_id', $request->status)->*/whereHas(
                    'queue_status',
                    function ($query) {
                        $query->whereIn('status', ['Pending', 'Active']);
                    }
                )->where('vehicle_id', $request->vehicle)->where('id', '<>', $request->id)->count() > 0
            ) {
                return response()->json(['error' => 'Vehicle already queued'], 401);
            }
            $queue = new Queue();
            if ($request->id > 0) {
                $queue = Queue::findOrFail($request->id);
            } else {
                $qn = Queue::whereBetween('created_at', [Carbon::today(), Carbon::now()])
                    ->where('terminus_id', $request->terminus)->where('route_id', $request->route)->count() + 1;
                $queue->queue_number = 'QN-' . $qn;
            }

            $queue->vehicle_id = $request->vehicle;
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
            if ($queue->save()) {
                $stages = RouteStage::where('route_id', $request->route)->get();
                foreach ($stages as $stage) {
                    if (QueuePlace::where('queue_id', $queue->id)->where('route_stage_id', $stage->id)->count() == 0) {
                        $queuePlace = new QueuePlace;
                        $queuePlace->queue_id = $queue->id;
                        $queuePlace->route_stage_id = $stage->id;
                        $queuePlace->save();
                    }
                }
                return response()->json(['success' => "Queue updated successfully!"]);
            } else {
                return response()->json(['error' => 'Unable to update queue'], 401);
            }
        } else {
            return response()->json(['error' => 'You do not have permissione to Add/Edit Queues'], 401);
        }
    }

    public function getQueue(Request $request)
    {
        $queue = Queue::where('id', $request->id)->with(['vehicle.seat', 'vehicle.sacco', 'route.from', 'route.to', 'queue_status', 'terminus.place'])->first();
        if ($queue == null) {
            return response()->json(['error' => 'Invalid queue ID'], 401);
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
            'seats.seat'
        ])
            ->where('queue_id', $queue->id);

        $bookings = $bookings->where(function ($query) use ($request) {
            $query->whereHas('queue.vehicle', function ($query) use ($request) {
                $query->where('plate', 'LIKE', '%' . $request->search . '%');
            })->orWhere('name', 'LIKE', '%' . $request->search . '%')
                ->orWhere('phone', 'LIKE', '%' . $request->search . '%');
        });
        $bookings = $bookings
            ->orderBy('created_at', 'DESC')->get();
        return response()->json(['queue' => $queue, 'bookings' => $bookings]);
    }

    public function getQueuesPlaces(Request $request)
    {
        $vehicleIds = VehicleUser::where('user_id', Auth::user()->id)->where('status', true)->pluck('vehicle_id');
        $queues = Queue::whereIn('vehicle_id', $vehicleIds)->whereIn('queue_status_id', QueueStatus::whereIn('status', ["Pending", "Active"])->pluck("id"))
            ->with('queue_places.route_stage.place')->get();
        return response()->json(['queues' => $queues]);
    }

    public function getGeofence()
    {
        $termini = SaccoTerminus::with('terminus.place')->where('sacco_id', Auth::user()->sacco_id)->get();

        $queues = Queue::with('queue_places.route_stage.place')->whereHas('queue_status', function ($query) {
            $query->whereIn('status', ['Active', 'Pending']);
        })->whereHas('vehicle.vehicle_user', function ($query) {
            $query->where('user_id', Auth::user()->id)->where('status', true);
        })->get();
        $vehicles = Vehicle::with(['sacco', 'seat'])->whereHas('vehicle_user', function ($query) {
            $query->where('user_id', Auth::user()->id)->where('status', true);
        })->get();
        return response()->json(['termini' => $termini, 'queues' => $queues, 'vehicles' => $vehicles]);
    }
}
