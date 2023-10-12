<?php

namespace App\Http\Controllers\APIs\Dashboard\Queues;

use App\Http\Controllers\Controller;
use App\Models\Place;
use App\Models\Queue;
use App\Models\QueuePlace;
use App\Models\Route;
use App\Models\RouteStage;
use App\Models\Terminus;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

class QueuesAPIController extends Controller
{
    public function __construct(){
        $this->middleware('auth:api');
    }
    public function getQueues(Request $request){
        
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $from_date = $request->date != ""?Carbon::parse($request->date):Carbon::today();
        $to_date = $from_date->copy()->addDays(1);

        $queues = Queue::with(['vehicle.sacco', 'vehicle.seat','route.from', 'route.to', 'queue_status', 'terminus.place', 'user', 'route.route_stages.place'])
        ->whereBetween('created_at', [$from_date, $to_date])->orderBy('queue_number', 'ASC');
        if($request->sacco > 0){
            $queues = $queues->whereHas('vehicle', function($query) use($request){
                $query->where('sacco_id', $request->sacco);
            });
        }
        if($request->route > 0){
            $queues = $queues->where('route_id',  $request->route);
        }
        if($request->terminus > 0){
            $queues = $queues->where('terminus_id', $request->terminu);
        }
        $queues = $queues->where(function($query)use($request){
            $query->where('queue_number', 'LIKE', '%'.$request->search.'%');
            $query->orWhereHas('vehicle',function($q)use($request){
                $q->where('plate', 'LIKE', '%'.$request->search.'%');
            })->orWhereHas('vehicle.sacco',function($q)use($request){
                $q->where('name', 'LIKE', '%'.$request->search.'%');
            });
        })->orderBy('created_at', 'DESC')->skip($offset)->take(20)->get();
        return response()->json(['queues'=>$queues]);
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
                Queue::where('route_id', $request->route)->/*where('queue_status_id', $request->status)->*/whereHas('queue_status', 
                function($query){
                    $query->whereNotIn('status', ['Pending', 'Active']);
                })->where('vehicle_id', $request->vehicle)->where('id', '<>', $request->id)->count() > 0
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

    public function getQueue(Request $request){
        $queue = Queue::where('id', $request->id)->with(['vehicle.seat', 'vehicle.sacco', 'route.from', 'route.to','queue_status', 'terminus.place'])->first();
        if($queue == null){
            return response()->json(['error'=>'Invalid queue ID'], 401);
        }
        $from = Place::where('name', $request->from)->first();
        $to = Place::where('name', $request->to)->first();
        return response()->json(['queue'=>$queue, 'from'=>$from, 'to'=>$to]);
    }
}
