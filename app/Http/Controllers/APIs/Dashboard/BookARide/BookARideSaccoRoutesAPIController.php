<?php

namespace App\Http\Controllers\APIs\Dashboard\BookARide;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Models\Place;
use App\Models\SaccoRoute;
use Illuminate\Http\Request;

class BookARideSaccoRoutesAPIController extends Controller
{
    use PaginatesResults;


    public function __construct(){
        $this->middleware('auth:sanctum');
    }

    public function getSaccoRoutes(Request $request){
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;

        $sacco_routes = SaccoRoute::select('sacco_routes.*')->with(['route.from', 'route.to', 'sacco','route.route_stages.place', 'route.route_stages'=>function($query){
            $query->orderBy('distance', 'ASC');
        }, 'sacco'])->whereHas('route.route_stages.place', function($query) use($request){
            $query->where('name', 'LIKE', '%'.$request->from.'%')
            ->orWhere('name', 'LIKE', '%'.$request->to.'%');
        });

        if(strlen($request->from)>0 && strlen($request->to)>0){
            $sacco_routes = $sacco_routes->join('route_stages as pickup', 'pickup.route_id', 'sacco_routes.route_id')
            ->join('route_stages as dropoff', function($join){
                $join->on('pickup.route_id', 'dropoff.route_id')->on('pickup.distance', '<', 'dropoff.distance');
            })->join('places as pickupPlace', 'pickupPlace.id', 'pickup.place_id')
            ->join('places as dropoffPlace', 'dropoffPlace.id', 'dropoff.place_id')
            ->where('pickupPlace.name', $request->from)->where('dropoffPlace.name', $request->to);
        }
        $sacco_routes = $sacco_routes->whereHas('sacco', function($query) use($request){
            // Only the name search is conditional. `status = true` is a real
            // business filter (active SACCOs only) and must apply either way, so
            // the guard goes INSIDE the closure rather than around the whereHas.
            $query->when(filled($request->search), fn ($q) => $q->where('name', 'LIKE', '%'.$request->search.'%'))
                ->where('status', true);
        });
        $__meta = $this->pageMeta($sacco_routes, $request, 20);
        $sacco_routes = $sacco_routes->skip($offset)->take(20)->get();
        return response()->json(array_merge(['sacco_routes'=>$sacco_routes], $__meta));
    }
}
