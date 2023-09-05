<?php

namespace App\Http\Controllers\Dashboard\Vehicles;

use App\Http\Controllers\Controller;
use App\Models\Sacco;
use App\Models\SaccoVehicle;
use App\Models\Status;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Yajra\DataTables\DataTables;

class VehicleController extends Controller
{
    public function __construct(){
        $this->middleware('auth');
        $this->middleware(['permission:View Vehicles']);
    }
    public function index()
    {
        $sacco = Sacco::find(Auth::user()->sacco_id);
        return view('dashboard.vehicles.vehicles', @compact('sacco'));
    }

    public function create(Request $request)
    {
        if(auth()->user()->can('Add Vehicles') || auth()->user()->can('Edit Vehicles')){
            $validator = Validator::make($request->all(), [
                'id' => 'required|min:0|integer',
                'plate' => 'required|string|unique:vehicles,plate,' . $request->id,
                'fleet_no' => 'string|nullable',
                'till_number' => 'integer|nullable',
                'sacco_id' => 'integer|nullable',
                'seat_id' => 'required|exists:seats,id',
                'merchant_short_code' => 'integer|nullable',
                'status' => 'required|min:0|integer',
            ]);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }
            $vehicle = new Vehicle;
            if ($request->id > 0) {
                $vehicle = Vehicle::findOrFail($request->id);
            }
            $vehicle->plate = $request->plate;
            $vehicle->fleet_no = $request->fleet_no;
            $vehicle->till_number = $request->till_number;
            $vehicle->merchant_short_code = $request->merchant_short_code;
            $vehicle->sacco_id = $request->sacco_id;
            $vehicle->user_id = Auth::user()->id;
            $vehicle->seat_id = $request->seat_id;
            $vehicle->status = $request->status;
            if ($vehicle->save()) {
                if($request->sacco_id > 0){
                    if(SaccoVehicle::where('vehicle_id', $vehicle->id)->where('sacco_id', $request->sacco_id)
                    ->where('end_date', null)->count() == 0){
                        $saccoVehicle = new SaccoVehicle;
                        $saccoVehicle->sacco_id = $request->sacco_id;
                        $saccoVehicle->vehicle_id = $vehicle->id;
                        $saccoVehicle->user_id = Auth::user()->id;
                        $saccoVehicle->start_date = Carbon::now();
                        if($saccoVehicle->save()){
                            SaccoVehicle::where('vehicle_id', $vehicle->id)->where('sacco_id', '<>',$request->sacco_id)
                            ->where('end_date', null)->update(['end_date'=>Carbon::now()]);
                        }
                    }
                }
                return response()->json(['success' => 'Vehicle saved successfully']);
            } else {
                return response()->json(['error' => 'Unable to update vehicle'], 401);
            }
        }else{
            return response()->json(['error' => 'Permissions to Add/Edit Vehicle Denied'], 401);   
        }
    }

    public function getVehicles(Request $request)
    {
        
        $vehicle = Vehicle::with(['sacco', 'user', 'seat']);
        if($request->sacco > 0){
            $vehicle = $vehicle->where('sacco_id', $request->sacco);
        }
        if($request->seat > 0){
            $vehicle = $vehicle->where('seat_id', $request->seat);
        }
        if($request->status != ""){
            $vehicle->where('status', $request->status);
        }
        if($request->search != ""){
            $vehicle = $vehicle->where(function($query) use($request){
                $query->where('plate', 'LIKE', '%'.$request->search.'%')->orWhere('fleet_no', 'LIKE', '%'.$request->search.'%')
                ->orWhere('till_number', 'LIKE', '%'.$request->search.'%')->orWhere('merchant_short_code', 'LIKE', '%'.$request->search.'%');
            });
        }

        return DataTables::of($vehicle)
            ->editColumn('created_at', function ($row) {
                return Carbon::parse($row->created_at)->diffForHumans();
            })->addColumn('action', function ($row) {
                $actionBtn = '<div style="white-space: nowrap;" class="text-end">' .
                    '<span class="d-none id">' . $row->id . '</span>' .
                    '<span class="d-none plate">' . $row->plate . '</span>' .
                    '<span class="d-none fleet_no">' . $row->fleet_no . '</span>' .
                    '<span class="d-none till_number">' . $row->till_number . '</span>' .
                    '<span class="d-none merchant_short_code">' . $row->merchant_short_code . '</span>' .
                    '<span class="d-none sacco_id">' . $row->sacco_id . '</span>' .
                    '<span class="d-none sacco">' . ($row->sacco != null?$row->sacco->name:'') . '</span>' .
                    '<span class="d-none seat_id">' . $row->seat_id . '</span>' .
                    '<span class="d-none seat">' . ($row->seat != null?$row->seat->name:'') . '</span>' .
                    '<span class="d-none status">' . $row->status . '</span>' .
                    '<span class="d-none user_id">' . $row->user_id . '</span>';
                    if(auth()->user()->can('Edit Vehicles'))
                        $actionBtn .= '<button class="btn-edit btn btn-primary btn-sm" data-toggle="modal" data-target="#vehicleModal"><i class="fas fa-edit"></i> Edit</button> ';
                    $actionBtn .= '<a href="javascript:void(0)" class="delete btn btn-outline-primary btn-sm"><i class="fas fa-eye"></i> View</a>'
                    . '</div>';
                return $actionBtn;
            })->addIndexColumn()->escapeColumns([])->make();
    }


    public function searchVehicles(Request $request)
    {
        $vehicles = Vehicle::where('plate', 'LIKE', '%' . $request->q . '%');
        if(Auth::user()->sacco_id > 0){
            $vehicles = $vehicles->where('sacco_id', Auth::user()->sacco_id);
        }
        $vehicles = $vehicles->skip(0)->take(5)->get();
        return json_encode($vehicles);
    }
}
