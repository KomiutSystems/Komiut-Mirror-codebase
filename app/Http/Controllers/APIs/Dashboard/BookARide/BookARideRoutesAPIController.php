<?php

namespace App\Http\Controllers\APIs\Dashboard\BookARide;

use App\Http\Controllers\Controller;
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
        $routes = Route::with(['from', 'to', 'route_stages.place','queues.queue_status'=>function($query){
            $query->where('status', 'Active')->orWhere('status', 'Pending');
        }, 'queues.vehicle.sacco', 'queues.vehicle.seat', 'queues.route.from', 'queues.route.to', 'queues.terminus.place'])->where('name', 'LIKE', '%'.$request->search.'%')
        ->orWhereHas('from', function($query) use($request){
            $query->where('name', 'LIKE', '%'.$request->search.'%');
        })->orWhereHas('to', function($query) use($request){
            $query->where('name', 'LIKE', '%'.$request->search.'%');
        })->skip($offset)->take(20)
        ->orderBy('name', 'ASC')->get();
        return response()->json(['routes'=>$routes]);
    }
}
