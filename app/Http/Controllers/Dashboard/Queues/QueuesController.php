<?php

namespace App\Http\Controllers\Dashboard\Queues;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Queue;
use App\Models\QueuePlace;
use App\Models\Route;
use App\Models\RouteStage;
use App\Models\Sacco;
use App\Models\SeatBooking;
use App\Models\Terminus;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Yajra\DataTables\DataTables;

class QueuesController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(['permission:View Queues']);
    }
    public function index()
    {
        $sacco = Sacco::find(Auth::user()->sacco_id);
        return view('dashboard.queues.queues', @compact('sacco'));
    }
    public function getQueues(Request $request)
    {

        $queueStatus = Queue::with(['vehicle.sacco', 'route.from', 'route.to', 'queue_status', 'terminus.place', 'user'])->orderBy('queue_number', 'ASC');
        return DataTables::of($queueStatus)
            ->filter(function ($query) use ($request) {
                $query->where(function ($q) use ($request) {
                    $q->where('queue_number', 'LIKE', '%' . $request->search . '%')
                        ->orWhereHas('vehicle', function ($qu) use ($request) {
                            $qu->where('plate', 'LIKE', '%' . $request->search . '%');
                        });
                })->whereBetween('created_at', [Carbon::parse($request->from_date), Carbon::parse($request->to_date)])
                    ->where('terminus_id', 'LIKE', '%' . $request->terminus . '%')->where('queue_status_id', 'LIKE', '%' . $request->status . '%')
                    ->where('route_id', 'LIKE', '%' . $request->route . '%');
                if ($request->sacco > 0) {
                    $query->whereHas('vehicle', function ($q) use ($request) {
                        $q->where('sacco_id', $request->sacco);
                    });
                }
            })->editColumn('created_at', function ($row) {
            return Carbon::parse($row->created_at)->diffForHumans();
        })->editColumn('schedule_time', function ($row) {
            return $row->queue_type ? Carbon::parse($row->schedule_time)->format("d M, Y H:i A") : "-";
        })->editColumn('start_time', function ($row) {
            $time = Carbon::parse($row->start_time);
            if ($row->queue_type == 0) {
                $time = $time->tz('Africa/Nairobi');
            }
            $time = $time->format("d M, Y H:i A");
            return $time;
        })->addColumn('action', function ($row) {
            $actionBtn = '<div style="white-space: nowrap;" class="text-end">' .
                '<span class="d-none id">' . $row->id . '</span>' .
                '<span class="d-none queue_number">' . $row->queue_number . '</span>' .
                '<span class="d-none vehicle_id">' . $row->vehicle->id . '</span>' .
                '<span class="d-none vehicle">' . $row->vehicle->plate . '</span>' .
                '<span class="d-none route_id">' . $row->route->id . '</span>' .
                '<span class="d-none route">' . $row->route->from->name . ' - ' . $row->route->to->name . ' (' . $row->route->name . ')</span>' .
                '<span class="d-none terminus">' . $row->terminus->name . ' (' . $row->terminus->place->name . ')</span>' .
                '<span class="d-none terminus_id">' . $row->terminus->id . '</span>' .
                '<span class="d-none status">' . $row->queue_status->name . ' (' . $row->queue_status->status . ')</span>' .
                '<span class="d-none status_id">' . $row->queue_status->id . '</span>' .
                '<span class="d-none amount">' . $row->amount . '</span>' .
                '<span class="d-none schedule_time">' . $row->schedule_time . '</span>' .
                '<span class="d-none queue_type">' . $row->queue_type . '</span>';
            if (auth()->user()->can('Edit Queues'))
                $actionBtn .= '<button class="btn-edit btn btn-primary btn-sm" data-toggle="modal" data-target="#routeModal"><i class="fas fa-edit"></i> Edit</button> ';
            $actionBtn .= '<a href="' . url('/queues/view/' . $row->id) . '" class="btn btn-outline-primary btn-sm"><i class="fas fa-eye"></i> View</a>'
                . '</div>';
            return $actionBtn;
        })->addIndexColumn()->escapeColumns([])->make();
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
                Queue::where('route_id', $request->route)->where('queue_status_id', $request->status)
                    ->whereDoesntHave('queue_status', function($query){
                        $query->whereIn('status', ['Completed', 'Suspended', 'Cancelled']);
                    })->where('vehicle_id', $request->vehicle)->where('id', '<>', $request->id)->count() > 0
            ) {
                return response()->json(['error' => 'Vehicle already queued!'], 401);
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

    public function viewQueue(Request $request)
    {
        $queue = Queue::where('id', $request->id)->with([
            'vehicle.sacco',
            'vehicle.seat.seat_arrangements',
            'route.from',
            'route.to',
            'queue_status',
            'queue_places.route_stage.place',
            'user'
        ])->first();
        if ($queue == null) {
            return redirect()->to('queues/all');
        }
        //return $queue->vehicle->seat->rows;
        return view('dashboard.queues.queue', @compact('queue'));
    }

    public function addPassengerBooking(Request $request)
    {
        if (auth()->user()->can('Add Passengers') || auth()->user()->can('Edit Passengers')) {
            $validator = Validator::make($request->all(), [
                'id' => 'required|integer|min:0',
                'name' => 'required|string',
                'phone' => 'required|digits:10',
                'seats.*' => 'required|integer',
                'queue_id' => 'required|integer|exists:queues,id',
                'from' => 'required|integer|exists:places,id',
                'to' => 'required|integer|exists:places,id|different:from',
                'amount' => 'required|integer|min:1',
            ]);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }
            //check if pickups valid
            $queue = Queue::find($request->queue_id);
            $from = RouteStage::with('place')->where('route_id', $queue->route_id)->where('place_id', $request->from)->first();
            $to = RouteStage::with('place')->where('route_id', $queue->route_id)->where('place_id', $request->to)->first();
            if ($from != null && $to != null) {
                if ($from->distance != null && $to->distance != null) {
                    if ($from->distance > $to->distance) {
                        return response()->json(['error' => $from->place->name . ' to ' . $to->place->name . ' is an invalid route!'], 401);
                    }
                }
            }
            //check seat availability
            $booked_seats = SeatBooking::with('seat')->whereHas('booking', function ($query) use ($request) {
                $query->where('queue_id', $request->queue_id);
            })->whereIn('seat_id', $request->seats)->where('booking_id', '<>', $request->id)->get();
            if ($booked_seats->count() > 0) {
                return response()->json(['booked_seats' => $booked_seats], 401);
            }
            $booking = new Booking;
            if ($request->id > 0) {
                $booking = Booking::findOrFail($request->id);
            }
            $booking->name = $request->name;
            $booking->phone = $request->phone;
            $booking->passengers = count($request->seats);
            $booking->queue_id = $request->queue_id;
            $booking->from_id = $request->from;
            $booking->to_id = $request->to;
            $booking->amount = $request->amount;
            $booking->created_by = Auth::user()->id;
            if ($booking->save()) {
                SeatBooking::where('booking_id', $booking->id)->whereNotIn('seat_id', $request->seats)->delete();
                foreach ($request->seats as $seat) {
                    if (SeatBooking::where('booking_id', $booking->id)->where('seat_id', $seat)->count() == 0) {
                        $seatBooking = new SeatBooking;
                        $seatBooking->booking_id = $booking->id;
                        $seatBooking->seat_id = $seat;
                        $seatBooking->save();
                    }
                }
                return response()->json(['success' => "Booking updated successfully!"]);
            } else {
                return response()->json(['error' => 'Unable to update booking'], 401);
            }
        } else {
            return response()->json(['error' => 'You do not have permissions to Add/Edit passengers'], 401);
        }
    }
    public function getBookedSeats(Request $request)
    {
        if (auth()->user()->can('View Passengers') || auth()->user()->can('View Passengers')) {
            $booked_seats = SeatBooking::with('seat')->whereHas('booking', function ($query) use ($request) {
                $query->where('queue_id', $request->id);
            })->get();
            return response()->json(['booked_seats' => $booked_seats]);
        } else {
            return response()->json(['error' => 'You do not have permissions to Add/Edit passengers'], 401);
        }
    }
    public function getPassengerBookings(Request $request)
    {

        $bookings = Booking::with(['from', 'to', 'creator', 'seats'])
            ->where('queue_id', $request->id)->orderBy('created_at', 'DEsC');

        return DataTables::of($bookings)
            ->filter(function ($query) use ($request) {
                /*$query->where(function($q) use($request){
                    $q->where('queue_number', 'LIKE', '%'.$request->search.'%')
                    ->orWhereHas('vehicle', function($qu) use($request){
                        $qu->where('plate', 'LIKE', '%'.$request->search.'%');
                    });
                })->whereBetween('created_at', [Carbon::parse($request->from_date),Carbon::parse($request->to_date)])
                ->where('terminus_id', 'LIKE', '%'.$request->terminus.'%')->where('queue_status_id', 'LIKE', '%'.$request->status.'%')
                ->where('route_id', 'LIKE', '%'.$request->route.'%');*/
            })->editColumn('created_at', function ($row) {
            return Carbon::parse($row->created_at)->diffForHumans();
        })->addColumn('action', function ($row) {
            $actionBtn = '<div style="white-space: nowrap;" class="text-end">' .
                '<span class="d-none id">' . $row->id . '</span>' .
                '<span class="d-none name">' . $row->name . '</span>' .
                '<span class="d-none phone">' . $row->phone . '</span>' .
                '<span class="d-none from">' . $row->from->name . '</span>' .
                '<span class="d-none from_id">' . $row->from_id . '</span>' .
                '<span class="d-none to">' . $row->to->name . '</span>' .
                '<span class="d-none to_id">' . $row->to_id . '</span>' .
                '<span class="d-none passengers">' . $row->passengers . '</span>' .
                '<span class="d-none amount">' . $row->amount . '</span>';
            $count = 0;
            foreach ($row->seats as $seat) {
                $count++;
                $actionBtn .= '<span class="d-none id-' . $count . '">' . $seat->seat_id . '</span>';
            }
            $actionBtn .= '<span class="d-none status">' . $row->status . '</span>' .
                '<span class="d-none active">' . $row->active . '</span>' .
                '<button class="btn-edit btn btn-primary btn-sm" data-toggle="modal" data-target="#routeModal"><i class="fas fa-edit"></i> Edit</button> ' .
                '<!--<a href="' . url('/queues/view/' . $row->id) . '" class="btn btn-outline-primary btn-sm"><i class="fas fa-eye"></i> View</a>-->'
                . '</div>';
            return $actionBtn;
        })->addIndexColumn()->escapeColumns([])->make();
    }
    public function searchPlaces(Request $request)
    {
        $places = RouteStage::where('route_id', $request->id)->with('place')->whereHas('place', function ($query) use ($request) {
            $query->where('name', 'LIKE', '%' . $request->q . '%');
        })->skip(0)->take(5)->get();
        return json_encode($places);
    }
}