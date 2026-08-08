<?php

namespace App\Http\Controllers\APIs\Dashboard\BookARide;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Models\QueueStatus;
use App\Models\Route;
use Illuminate\Http\Request;

class BookARideRoutesAPIController extends Controller
{
    use PaginatesResults;

    public function __construct(){
        $this->middleware('auth:sanctum');
    }

    /**
     * Search routes by pickup & dropoff
     *
     * Point-first ("Uber with fixed stops"): the passenger picks a pickup and a
     * dropoff point and we return the routes that serve that segment — a route
     * matches when both points are stops on it and the pickup comes before the
     * dropoff (pickup.distance < dropoff.distance). Prefer the id-based
     * `from_place_id`/`to_place_id`; the name form is a legacy fallback.
     * With no pickup/dropoff, returns all active routes.
     *
     * @authenticated
     *
     * @queryParam from_place_id integer Pickup stop (place) id. Example: 12
     * @queryParam to_place_id integer Dropoff stop (place) id. Example: 18
     * @queryParam from string Legacy: pickup place name (exact). Example: Nairobi CBD
     * @queryParam to string Legacy: dropoff place name (exact). Example: Thika
     * @queryParam page integer Page number (20 per page). Example: 1
     */
    public function getRoutes(Request $request){
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $statuses = QueueStatus::where('status', 'Active')->orWhere('status', 'Pending')->pluck('id');
        $routes = Route::select('routes.*')->with(['from', 'to', 'route_stages.place','queues'=>function($query) use($statuses){
            $query->whereIn('queue_status_id', $statuses);
        }, 'queues.vehicle.sacco', 'queues.vehicle.seat', 'queues.route.from',
        'queues.route.to', 'queues.terminus.place', 'queues.queue_status']);

        // Preferred: id-based segment search (uses the route_stages(place_id) and
        // (route_id, distance) indexes; pickup strictly before dropoff).
        if($request->filled('from_place_id') && $request->filled('to_place_id')){
            $routes = $routes->join('route_stages as pickup', 'pickup.route_id', 'routes.id')
            ->join('route_stages as dropoff', function($join){
                $join->on('dropoff.route_id', '=', 'pickup.route_id')
                     ->on('pickup.distance', '<', 'dropoff.distance');
            })
            ->where('pickup.place_id', (int) $request->from_place_id)
            ->where('dropoff.place_id', (int) $request->to_place_id)
            ->distinct();
        } elseif(strlen($request->from)>0 && strlen($request->to)>0){
            // Legacy fallback: match stops by name (kept for existing clients).
            $routes = $routes->join('route_stages as pickup', 'pickup.route_id', 'routes.id')
            ->join('route_stages as dropoff', function($join){
                $join->on('dropoff.route_id', '=', 'pickup.route_id')
                     ->on('pickup.distance', '<', 'dropoff.distance');
            })->join('places as pickupPlace', 'pickupPlace.id', 'pickup.place_id')
            ->join('places as dropoffPlace', 'dropoffPlace.id', 'dropoff.place_id')
            ->where('pickupPlace.name', $request->from)->where('dropoffPlace.name', $request->to)
            ->distinct();
        }
        $__meta = $this->pageMeta($routes, $request, 20);
        $routes = $routes->where('routes.status', true)->skip($offset)->take(20)
        ->orderBy('routes.name', 'ASC')->get();
        return response()->json(array_merge(['routes'=>$routes], $__meta));
    }
}
