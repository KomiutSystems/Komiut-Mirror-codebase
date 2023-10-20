<?php

namespace App\Http\Controllers\APIs\Dashboard\BookARide;

use App\Http\Controllers\Controller;
use App\Models\QueueStatus;
use App\Models\Route;
use Illuminate\Http\Request;

class BookARideRoutesAPIController extends Controller
{
    public function __construct(){
        $this->middleware('auth:api');
    }

    public function getRoutes(Request $request){
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $statuses = QueueStatus::where('status', 'Active')->orWhere('status', 'Pending')->pluck('id');
        $routes = Route::with(['from', 'to', 'route_stages.place','queues'=>function($query) use($statuses){
            $query->whereIn('queue_status_id', $statuses);
        }, 'queues.vehicle.sacco', 'queues.vehicle.seat', 'queues.route.from', 'queues.route.to', 'queues.terminus.place'])
        ->whereHas('from', function($q) use($request){
            $q->where('name', 'LIKE', '%'.$request->from.'%');
        })->whereHas('to', function($q) use($request){
            $q->where('name', 'LIKE', '%'.$request->to.'%');
        })->skip($offset)->take(20)
        ->orderBy('name', 'ASC')->get();
        return response()->json(['routes'=>$routes]);
    }
}
