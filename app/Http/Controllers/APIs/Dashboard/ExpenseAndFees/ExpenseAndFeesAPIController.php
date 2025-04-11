<?php

namespace App\Http\Controllers\APIs\Dashboard\ExpenseAndFees;

use App\Http\Controllers\Controller;
use App\Models\VehicleExpenseAndFee;
use App\Models\VehicleUser;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ExpenseAndFeesAPIController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request)
    {
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $from_date = $request->date != "" ? Carbon::parse($request->date) : Carbon::today();
        $to_date = $from_date->copy()->addDays(1);

        $vehicleExpenseFees = VehicleExpenseAndFee::with('vehicle.sacco', 'expense_fee')
            ->whereBetween('trans_date', [$from_date, $to_date]);

        $vehicles = VehicleUser::where('user_id', auth()->user()->id)
        ->where('status', true)->pluck('vehicle_id');
        if(count($vehicles)>0){
            $vehicleExpenseFees = $vehicleExpenseFees->whereIn('vehicle_id', $vehicles);
        }
        if ($request->sacco > 0) {
            $vehicleExpenseFees = $vehicleExpenseFees->whereHas('vehicle', function ($query) use ($request) {
                $query->where('sacco_id', $request->sacco);
            });
        }
        if ($request->expense_fee > 0) {
            $vehicleExpenseFees = $vehicleExpenseFees->where('expense_fee_id', $request->expense_fee);
        }
        if ($request->status != "") {
            $vehicleExpenseFees = $vehicleExpenseFees->where('status', $request->status);
        }
        $vehicleExpenseFees = $vehicleExpenseFees->whereHas('vehicle', function ($query) use ($request) {
            $query->where('plate', 'LIKE', '%' . $request->search . '%');
        })->skip($offset)->take(20)->get();
        return response()->json(['vehicle_expense_and_fees' => $vehicleExpenseFees]);
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
            $route = Route::with(['from', 'to'])->where('id', $request->route)->first();
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
                $queueStatus = QueueStatus::where('status', 'Completed')->first();
                if ($queueStatus != null) {
                    Queue::where('route_id', $request->route)-> /*where('queue_status_id', $request->status)->*/whereHas(
                        'queue_status',
                        function ($query) {
                            $query->whereIn('status', ['Pending', 'Active']);
                        }
                    )->where('vehicle_id', $request->vehicle)->where('id', '<>', $request->id)
                    ->update(['queue_status_id' => $queueStatus->id, 'updated_at' => Carbon::now()]);
                }else{
                    return response()->json(['error' => 'Vehicle already queued'], 401);
                }
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
                $title = $vehicle->plate." Queued";
                $message = $vehicle->plate." queued for ".$route->from->name." to ".$route->to->name;
                foreach($tokens as $token){
                    dispatch(new SendFCMJob($token, $title, $message, 'queues_screen', 0));
                }
                return response()->json(['success' => "Queue updated successfully!"]);
            } else {
                return response()->json(['error' => 'Unable to update queue'], 401);
            }
        } else {
            return response()->json(['error' => 'You do not have permissione to Add/Edit Queues'], 401);
        }
    }
}
