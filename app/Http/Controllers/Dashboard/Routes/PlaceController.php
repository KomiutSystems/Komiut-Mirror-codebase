<?php
namespace App\Http\Controllers\Dashboard\Routes;

use App\Http\Controllers\Controller;
use App\Models\Place;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Yajra\DataTables\DataTables;

class PlaceController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(['permission:View Places']);
    }
    public function index(){
        return view('dashboard.routes.places');
    }

    public function addPlace(Request $request){
        if(auth()->user()->can('Add Places') || auth()->user()->can('Add Places')){
            $validator = Validator::make($request->all(), [
                'id'=>'required|min:0|integer',
                'name'=>'required|string',
                'county_name'=>'nullable|string',
                'longitude'=>'nullable|numeric',
                'latitude'=>'nullable|numeric',
                'status'=>'required|min:0|max:1|integer',
            ]);
            if($validator->fails()){
                return response()->json(['errors'=>$validator->messages()], 400);
            }
            
            if(Place::where('name', $request->name)->where('county_name', $request->county_name)->where('id','<>', $request->id)->count() > 0){
                return response()->json(['error'=>'Place already exists'], 401);
            }
            $place = new Place;
            if($request->id > 0){
                $place = Place::findOrFail($request->id);
            }
            $place->name = $request->name;
            $place->county_name = $request->county_name;
            $place->longitude = $request->longitude;
            $place->latitude = $request->latitude;
            $place->status = $request->status;
            if($place->save()){
                return response()->json(['success'=>'Place updated successfully']);
            }else{
                return response()->json(['error'=>'Unable to update place'], 401);
            }
        }else{
            return response()->json(['error'=>'You do not have permissions to Add/Edit Places'], 401);
        }
    }

    public function getPlaces(Request $request): JsonResponse
    {
        return DataTables::of(Place::orderBy('name', 'ASC'))
            ->editColumn('created_at', function ($row) {
                return Carbon::parse($row->created_at)->diffForHumans();
            })->addColumn('action', function ($row) {
                $actionBtn = '<div style="white-space: nowrap;" class="text-end">' .
                    '<span class="d-none id">' . $row->id . '</span>' .
                    '<span class="d-none name">' . $row->name . '</span>' .
                    '<span class="d-none county_name">' . $row->county_name . '</span>' .
                    '<span class="d-none longitude">' . $row->longitude . '</span>' .
                    '<span class="d-none latitude">' . $row->latitude . '</span>' .
                    '<span class="d-none status">' . $row->status . '</span>';
                if(auth()->user()->can('Edit Places'))
                    $actionBtn .='<button class="btn-edit btn btn-primary btn-sm" data-toggle="modal" data-target="#placeModal"><i class="fas fa-edit"></i> Edit</button> ';
                
                $actionBtn .= ' <a href="'.url("routes/places/view/".$row->id).'" class="delete btn btn-outline-primary btn-sm"><i class="fas fa-eye"></i> View</a> 
                    </div>';
                return $actionBtn;
            })->addIndexColumn()->escapeColumns([])->make();
    }

    public function place(Request $request){
        $place = Place::where('id', $request->id)->first();
        if($place == null){
            return redirect()->to('routes/places');
        }
        return view('dashboard.routes.place', @compact('place'));
    }
    public function searchPlace(Request $request)
    {
        return json_encode(Place::select('id', 'name')
            ->where('name', 'LIKE', '%' . $request->q . '%')
            ->skip(0)->take(5)->get());
    }
}
