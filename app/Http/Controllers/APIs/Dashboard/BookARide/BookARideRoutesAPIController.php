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
        $routes = Route::select('routes.*')->with(['from', 'to', 'route_stages.place','queues'=>function($query) use($statuses){
            $query->whereIn('queue_status_id', $statuses);
        }, 'queues.vehicle.sacco', 'queues.vehicle.seat', 'queues.route.from',
        'queues.route.to', 'queues.terminus.place', 'queues.queue_status'])
        /*->where(function($query) use($request){
            $query->where(function($query) use($request){
                $query->whereHas('from', function($q) use($request){
                    $q->where('name', 'LIKE', '%'.$request->from.'%');
                })->whereHas('to', function($q) use($request){
                    $q->where('name', 'LIKE', '%'.$request->to.'%');
                });
            })->orWhereHas('route_stages', function($query) use($request){
                $query->whereHas('place', function($q) use($request){
                    $q->where('name', 'LIKE', '%'.$request->from.'%')->where('name', 'LIKE', '%'.$request->to.'%');
                });
            });
        })*/;
        if(strlen($request->from)>0 && strlen($request->to)>0){
            $routes = $routes->join('route_stages as pickup', 'pickup.route_id', 'routes.id')
            ->join('route_stages as dropoff', function($join){
                $join->on('pickup.route_id', 'dropoff.route_id')->on('pickup.distance', '<=', 'dropoff.distance');
            })->join('places as pickupPlace', 'pickupPlace.id', 'pickup.place_id')
            ->join('places as dropoffPlace', 'dropoffPlace.id', 'dropoff.place_id')
            ->where('pickupPlace.name', $request->from)->where('dropoffPlace.name', $request->to);
        }
        $routes = $routes->where('routes.status', true)->skip($offset)->take(20)
        ->orderBy('routes.name', 'ASC')->get();
        return response()->json(['routes'=>$routes]);
    }
}
