<?php

namespace App\Http\Controllers\APIs\Dashboard\Queues;

use App\Http\Controllers\Controller;
use App\Models\Queue;
use Carbon\Carbon;
use Illuminate\Http\Request;

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

        $queues = Queue::with(['vehicle.sacco', 'route.from', 'route.to', 'queue_status', 'terminus.place', 'user'])
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
}
