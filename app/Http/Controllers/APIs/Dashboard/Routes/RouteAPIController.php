<?php

namespace App\Http\Controllers\APIs\Dashboard\Routes;

use App\Http\Controllers\Controller;
use App\Models\Route;
use App\Models\RouteStage;
use Illuminate\Http\Request;

class RouteAPIController extends Controller
{
    public function __construct(){
        $this->middleware('auth:api');
    }
    
    public function getRoutes(Request $request){
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $routes = Route::with(['from', 'to'])->where('name', 'LIKE', '%'.$request->search.'%')
        ->orWhereHas('from', function($query) use($request){
            $query->where('name', 'LIKE', '%'.$request->search.'%');
        })->orWhereHas('to', function($query) use($request){
            $query->where('name', 'LIKE', '%'.$request->search.'%');
        })->skip($offset)->take(20)
        ->orderBy('name', 'ASC')->get();
        return response()->json(['routes'=>$routes]);
    }

    public function getRoutePlaces(Request $request){
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $route_stages = RouteStage::select('places.*')->where('route_stages.route_id', $request->id)
        ->join('places', 'places.id', 'route_stages.place_id')->where('places.name', 'LIKE', '%'.$request->search.'%')
        ->skip($offset)->take(20)
        ->orderBy('places.name', 'ASC')->get();
        return response()->json(['places'=>$route_stages]);
    }
}
