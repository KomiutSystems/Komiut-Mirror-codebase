<?php

namespace App\Http\Controllers\APIs\Dashboard\Routes;

use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Models\Place;
use App\Models\Route;
use App\Models\RouteStage;
use App\Models\SaccoRoute;
use App\Services\Sql\LikeSql;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RouteAPIController extends Controller
{
    use PaginatesResults;

    public function __construct(){
        $this->middleware('auth:sanctum');
    }

    public function getRoutes(Request $request){
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $routes = Route::with(['from', 'to']);
        if(strlen($request->from) > 0){
            $routes = $routes->whereHas('from', function($query) use($request){
                $query->where('name',$request->from);
            });
        }
        if(strlen($request->to) > 0){
            $routes = $routes->whereHas('to', function($query) use($request){
                $query->where('name',$request->to);
            });
        }
        if(auth()->user()->sacco_id>0){
            $routes = $routes->whereIn('id', SaccoRoute::where('sacco_id', auth()->user()->sacco_id)->pluck('route_id'));
        }
        $__meta = $this->pageMeta($routes, $request, 20);
        $routes = $routes->skip($offset)->take(20)
        ->orderBy('name', 'ASC')->get();
        return response()->json(array_merge(['routes'=>$routes], $__meta));
    }

    public function getRoutePlaces(Request $request){
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $route_stages = RouteStage::with('place')->where('route_stages.route_id', $request->id)
        ->whereHas('place', function($query) use($request){
            $query->when(filled($request->search), fn ($q) => $q->where('name', LikeSql::op(), '%'.$request->search.'%'));
        })
        ->skip($offset)->take(20)->orderBy('distance', 'ASC')->get();
        return response()->json(['route_stages'=>$route_stages]);
    }
    public function addRoute(Request $request)
    {
        if(auth()->user()->can('Add Routes') || auth()->user()->can('Edit Routes')){
            $validator = Validator::make($request->all(), [
                'id' => 'required|min:0|integer',
                'name' => 'nullable|string',
                'from' => 'required|string',
                'to' => 'required|string|different:from',
                'status'=>'required|min:0|max:1'
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }
            $fromPlace = Place::where('name', $request->from)->first();
            $toPlace = Place::where('name', $request->to)->first();
            if($fromPlace == null || $toPlace == null){
                return response()->json(['error'=>'From/To Place not found!'], 401);
            }
            if(Route::where('from_id', $fromPlace->id)->where('to_id', $toPlace->id)->where('id','<>', $request->id)->count() > 0){
                return response()->json(['error'=>'Route already exists'], 401);
            }
            $route = new Route();
            if ($request->id > 0) {
                // SCOPED find. `routes` is SACCO-owned now, so another SACCO's
                // route resolves to null here and 404s — which is what closes
                // the hole this line used to be: an id plus a permission check,
                // no ownership test, and any SACCO Admin could re-destination
                // any route on the platform. sacco_routes, route_fares and live
                // queues all point at routes.id, so that silently took another
                // SACCO's fares and trips with it.
                $route = Route::find($request->id);

                if ($route === null) {
                    return response()->json(['error' => 'That route is not yours to edit.'], 404);
                }
            } else {
                $route->sacco_id = auth()->user()->currentSaccoId();

                if ($route->sacco_id === null) {
                    return response()->json(['error' => 'You must belong to a SACCO to create a route.'], 403);
                }
            }
            $route->name = $request->name;
            $route->from_id = $fromPlace->id;
            $route->to_id = $toPlace->id;
            $route->status = $request->status;
            if ($route->save()) {
                // Link the route to the creator's SACCO. getRoutes() lists only
                // routes present in sacco_routes, so without this a route saved
                // here is invisible on the very screen that created it — which
                // is why callers drifted to book_a_ride/routes (unfiltered) and
                // this endpoint stopped being the authoritative list.
                //
                // firstOrCreate keeps an edit (id > 0) idempotent rather than
                // stacking a duplicate pivot row on every save.
                $saccoId = auth()->user()->sacco_id ?? null;
                if ($saccoId > 0) {
                    SaccoRoute::firstOrCreate([
                        'sacco_id' => $saccoId,
                        'route_id' => $route->id,
                    ], [
                        'user_id' => auth()->id(),
                        // amount/min_amount are NOT NULL with no DB default; the
                        // fare is set later on the fare screen, so seed at 0
                        // rather than fail the route save on a column this
                        // endpoint does not collect.
                        'amount' => 0,
                        'min_amount' => 0,
                        'status' => 1,
                    ]);
                }

                if ($request->id == 0) {
                    return response()->json(["success" => "Route saved successfully!", "route_id" => $route->id], 200);
                } else {
                    return response()->json(["success" => "Route updated successfully!", "route_id" => $route->id], 200);
                }
            } else {
                return response()->json(["errors" => "Something went wrong!"], 401);
            }
        }else{
            return response()->json(["errors" => "You do not have permissions to Add/Edit routes!"], 401);
        }
    }
    public function addRouteStage(Request $request){

        $validator = Validator::make($request->all(), [
            'id'=>'required|integer|min:0',
            'route_id' => 'required|integer|exists:routes,id',
            'place' => 'required|string',
            'status' => 'required|integer|min:0|max:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }
        // Ownership. `route_id` was validated for EXISTENCE only, so a stage
        // could be added to — or re-parented onto — any SACCO's route.
        // Route::find is scoped, so another SACCO's route is simply not there.
        if (Route::find((int) $request->route_id) === null) {
            return response()->json(['error' => 'That route is not yours to edit.'], 404);
        }

        $place = Place::where('name', $request->place)->first();
        if($place == null){
            return response()->json(['error'=>'Invalid stage provided'], 401);
        }
        if(RouteStage::where('route_id', $request->route_id)->where('place_id', $place->id)
        ->where('id', '<>',$request->id)->count() > 0){
            return response()->json(['error'=>'Stage already exists'], 401);
        }
        $routeStage = new RouteStage();
        if($request->id > 0){
            $routeStage = RouteStage::findOrFail($request->id);
        }
        // New stops are appended in travel order; edits keep their position.
        if(! $routeStage->exists){
            $routeStage->sequence = RouteStage::nextSequence((int) $request->route_id);
        }
        $routeStage->route_id = $request->route_id;
        $routeStage->place_id = $place->id;
        $routeStage->status = $request->status;
        if($routeStage->save()){
            if($routeStage->longitude == null || $routeStage->latitude == null){
                $route = Route::with('from')->where('id', $request->route_id)->first();
                //$place = Place::find($request->place);
                $routeStage->longitude = $place->longitude;
                $routeStage->latitude = $place->latitude;
                // NEVER null: route_stages.distance is NOT NULL, and this
                // wrote null whenever the origin place had no longitude —
                // which is every place in production, all 1,980 of them. So the
                // happy path here was a 500.
                //
                // Falls back to appending the stop after the current furthest
                // one. Segment search only depends on distance INCREASING along
                // the route (pickup.distance < dropoff.distance), so preserving
                // order keeps the route bookable until real coordinates arrive.
                $routeStage->distance = RouteStage::distanceFrom($route?->from, $place)
                    ?? RouteStage::nextDistance((int) $request->route_id);
                $routeStage->save();
            }
            return response()->json(['success'=>"Stage added successfully!"]);
        }else{
            return response()->json(['error'=>'Unable to add stage'], 401);
        }
    }

    public function getRouteStage(Request $request){
        $routeStage = RouteStage::with('place')->where('id', $request->id)->first();
        return response()->json(['route_stage'=>$routeStage]);
    }
    public function addRouteStageCoords(Request $request){

        $validator = Validator::make($request->all(), [
            'id'=>'required|integer|exists:route_stages,id',
            // Ranges are the real world's, not Kenya's: a mistyped sign is
            // caught but nothing legitimate is refused. Without these, latitude
            // 999 was accepted and stored.
            'longitude' => 'required|numeric|between:-180,180',
            'latitude' => 'required|numeric|between:-90,90',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }
        $routeStage = RouteStage::find($request->id);

        // Ownership. RouteStage carries no scope of its own, so the tenant
        // boundary has to be reached through its route — which IS scoped. This
        // used to move the coordinates of any stage on the platform by id.
        $route = Route::with('from')->where('id', $routeStage->route_id)->first();

        if ($route === null) {
            return response()->json(['error' => 'That stage is not yours to edit.'], 404);
        }

        $routeStage->longitude = $request->longitude;
        $routeStage->latitude = $request->latitude;
        // Same NOT NULL guard as addRouteStage: null must never reach the
        // column. Keeping the stage's existing distance is the right fallback
        // here — unlike a brand-new stop, this row already has a position in
        // the route's ordering and dropping a pin must not move it.
        $routeStage->distance = RouteStage::distanceFrom($route->from, $routeStage)
            ?? (float) ($routeStage->distance ?? RouteStage::nextDistance((int) $routeStage->route_id));
        if($routeStage->save()){
            return response()->json(['success'=>"Stage coordinates updated successfully!"]);
        }else{
            return response()->json(['error'=>'Unable to add stage coordinates'], 401);
        }
    }
}
