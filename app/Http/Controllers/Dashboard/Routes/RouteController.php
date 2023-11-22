<?php
namespace App\Http\Controllers\Dashboard\Routes;

use App\Http\Controllers\Controller;
use App\Models\Place;
use App\Models\Route;
use App\Models\Sacco;
use App\Models\Status;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Yajra\DataTables\DataTables;

class RouteController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(['permission:View Routes']);
    }
    public function index()
    {
        return view('dashboard.routes.routes');
    }


    public function route($id)
    {
        $statuses = Status::all();
        $route = Route::find($id);

        return view('dashboard.route', compact('statuses','route'));
    }

    public function getRoutes(Request $request)
    {
        $routes = Route::with(['from', 'to']);

        if ($request->has('search') && !empty($request->search)) {
            $routes = $routes->where('name', 'like', '%' . $request->search . '%');
        }
       if($request->from > 0){
        $routes = $routes->where('from_id', $request->from);
       }
       if($request->to > 0){
        $routes = $routes->where('to_id', $request->to);
       }
       if($request->status != ""){
        $routes = $routes->where("status", $request->status);
       }

        return DataTables::of($routes)
            ->editColumn('created_at', function ($row) {
                return Carbon::parse($row->created_at)->diffForHumans();
            })->addColumn('action', function ($row) {
                $actionBtn = '<div style="white-space: nowrap;" class="text-end">' .
                    '<span class="d-none id">' . $row->id . '</span>' .
                    '<span class="d-none name">' . $row->name . '</span>' .
                    '<span class="d-none from_id">' . $row->from_id . '</span>' .
                    '<span class="d-none to_id">' . $row->to_id . '</span>' .
                    '<span class="d-none status">' . $row->status . '</span>';
                if(auth()->user()->can('Edit Routes'))
                    $actionBtn .= '<button class="btn-edit btn btn-primary btn-sm" data-toggle="modal" data-target="#routeModal"><i class="fas fa-edit"></i> Edit</button> ';
                $actionBtn .= '<a href="' . url("routes/view/" . $row->id) . '" class="btn btn-outline-primary btn-sm"><i class="fas fa-eye"></i> View</a>' . '</div>';
                return $actionBtn;
            })->addIndexColumn()->escapeColumns([])->make();

    }

    public function addRoute(Request $request)
    {
        if(auth()->user()->can('Add Routes') || auth()->user()->can('Edit Routes')){
            $validator = Validator::make($request->all(), [
                'id' => 'required|min:0|integer',
                'name' => 'nullable|string',
                'from_id' => 'required|numeric',
                'to_id' => 'required|numeric|different:from_id',
                'status'=>'required|integer|min:0|max:1'
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }
            if(Route::where('from_id', $request->from_id)->where('to_id', $request->to_id)->where('id','<>', $request->id)->count() > 0){
                return response()->json(['error'=>'Route already exists'.$request->id.','.$request->from_id.','.$request->to_id], 401);
            }
            $route = new Route();
            if ($request->id > 0) {
                $route = Route::findOrFail($request->id);
            }
            $route->name = $request->name;
            $route->from_id = $request->from_id;
            $route->to_id = $request->to_id;
            $route->status = $request->status;
            if ($route->save()) {
                if ($request->id == 0) {
                    return response()->json(["success" => "Route saved successfully!"], 200);
                } else {
                    return response()->json(["success" => "Route updated successfully!"], 200);
                }
            } else {
                return response()->json(["errors" => "Something went wrong!"], 401);
            }
        }else{
            return response()->json(["errors" => "You do not have permissions to Add/Edit routes!"], 401);
        }
    }

    public function searchPlaces(Request $request)
    {
        return json_encode(Place::select('id', 'name')
            ->where('name', 'LIKE', '%' . $request->q . '%')
            ->skip(0)->take(5)->get());
    }

    public function searchRoutes(Request $request)
    {
        $search = explode( '-', $request->q );
        $routes = Route::with(['from', 'to'])->whereHas('from', function($query) use($search){
            $query->where('name', 'LIKE', '%'.$search[0].'%');
        });
        if(count($search) > 1){
            $routes = $routes->whereHas('to', function($query) use($search){
                $query->where('name', 'LIKE', '%'.$search[1].'%');
            });
        }
        $routes = $routes->skip(0)->take(5)->get();
        return json_encode($routes);
    }
}
