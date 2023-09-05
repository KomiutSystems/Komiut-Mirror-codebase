<?php

namespace App\Http\Controllers\Dashboard\Routes;

use App\Http\Controllers\Controller;
use App\Models\Place;
use App\Models\Route;
use App\Models\RouteStage;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Yajra\DataTables\DataTables;

class RouteStageController extends Controller
{ 
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(['permission:View Routes']);
    }
    public function index(Request $request){
        $route = Route::with(['from', 'to'])->where('id', $request->id)->first();
        if($route == null){
            return redirect()->to('routes');
        }
        return view('dashboard.routes.route', @compact('route'));
    }
    public function addRouteStage(Request $request){

        $validator = Validator::make($request->all(), [
            'id'=>'required|integer|min:0',
            'route_id' => 'required|integer|exists:routes,id',
            'place' => 'required|integer|exists:places,id',
            'status' => 'required|integer|min:0|max:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }
        if(RouteStage::where('route_id', $request->route_id)->where('place_id', $request->place)
        ->where('id', '<>',$request->id)->count() > 0){
            return response()->json(['error'=>'Stage already exists'], 401);
        }
        $routeStage = new RouteStage();
        if($request->id > 0){
            $routeStage = RouteStage::findOrFail($request->id);
        }
        $routeStage->route_id = $request->route_id;
        $routeStage->place_id = $request->place;
        $routeStage->status = $request->status;
        if($routeStage->save()){
            if($routeStage->longitude == null || $routeStage->latitude == null){
                $route = Route::with('from')->where('id', $request->route_id)->first();
                $place = Place::find($request->place);
                $routeStage->longitude = $place->longitude;
                $routeStage->latitude = $place->latitude;
                $distance = null;
                if($route->from->longitude != null){
                    $distance = round((sqrt(pow(69.1*($route->from->latitude - $place->latitude), 2) + 
                    pow(69.1 * ($place->longitude - $route->from->longitude)* cos($route->from->latitude/57.3),2)))*1.609344,2);
                }
                $routeStage->distance = $distance;
                $routeStage->save();
            }
            return response()->json(['success'=>"Stage added successfully!"]);
        }else{
            return response()->json(['error'=>'Unable to add stage'], 401);
        }
    }


    public function getRouteStages(Request $request){

        $stageRoute = RouteStage::with('place')->where('route_id', $request->id);

        return DataTables::of($stageRoute)
            ->filter(function($query) use($request){
                $query->where(function($q) use($request){
                    $q->whereHas('place', function($qu) use($request){
                        $qu->where('name', 'LIKE', '%'.$request->search.'%');
                    });
                });
            })->editColumn('created_at', function ($row) {
                return Carbon::parse($row->created_at)->diffForHumans();
            })->addColumn('action', function ($row) {
                $actionBtn = '<div style="white-space: nowrap;" class="text-end">' .
                    '<span class="d-none id">' . $row->id . '</span>' .
                    '<span class="d-none place_id">' . $row->place_id . '</span>' .
                    '<span class="d-none place">' . $row->place->name . '</span>' .
                    '<span class="d-none route_id">' . $row->route_id . '</span>' .
                    '<span class="d-none status">' . $row->status . '</span>';
                    if(auth()->user()->can('Edit Routes'))
                        $actionBtn .= '<button class="btn-edit btn btn-primary btn-sm" data-toggle="modal" data-target="#placeModal"><i class="fas fa-edit"></i> Edit</button> ';
                    $actionBtn .= '<a href="' . url('/routes/stages/view/' . $row->id) . '" class="delete btn btn-outline-primary btn-sm"><i class="fas fa-eye"></i> View</a>'
                    . '</div>';
                return $actionBtn;
            })->addIndexColumn()->escapeColumns([])->make();
        }

    public function viewRouteStage(Request $request){
        $routeStage = RouteStage::with(['place','route.from', 'route.to'])->where('id', $request->id)->first();
        return view('dashboard.routes.route_stage', ['routeStage'=>$routeStage]);
    }
    public function updateRouteStage(Request $request){
        if(auth()->user()->can('Add Routes') || auth()->user()->can('Edit Routes')){
            $validator = Validator::make($request->all(), [
                'id'=>'required|integer|min:1',
                'longitude' => 'required|numeric',
                'latitude' => 'required|numeric',
                'status' => 'required|integer|min:0|max:1',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }
            $routeStage = RouteStage::findOrFail($request->id);
            if($routeStage == null){
                return response()->json(['error'=>"Invalid Stage ID"]);
            }
            //check distance
            $route = Route::where('id', $routeStage->route_id)->with(['from'])->first();
            $distance = null;
            if($route->from->longitude != null){
                $distance = round((sqrt(pow(69.1*($route->from->latitude - $request->latitude), 2) + 
                pow(69.1 * ($request->longitude - $route->from->longitude)* cos($route->from->latitude/57.3),2)))*1.609344,2);
            }
            
            $routeStage->longitude = $request->longitude;
            $routeStage->latitude = $request->latitude;
            $routeStage->latitude = $request->latitude;
            $routeStage->distance = $distance;
            $routeStage->status = $request->status;
            if($routeStage->save()){
                return response()->json(['success'=>"Stage updated successfully!"]);
            }else{
                return response()->json(['error'=>'Unable to update stage'], 401);
            }
        }else{
            return response()->json(["errors" => "You do not have permissions to Add/Edit routes!"], 401);
        }
    }
    public function removeRouteStage($id)
    {
        $routeStage = RouteStage::find($id)->update(['status_id' => 2]);
        if ($routeStage) {
            return redirect()->back()->with('success', 'Route Stage removed successfully');
        }
        else {
            return redirect()->back()->with('error', 'Failed to remove Route Stage');
        }
    }
}
