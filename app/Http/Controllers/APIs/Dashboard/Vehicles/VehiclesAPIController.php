<?php

namespace App\Http\Controllers\APIs\Dashboard\Vehicles;

use App\Http\Controllers\Controller;
use App\Models\Sacco;
use App\Models\SaccoVehicle;
use App\Models\Seat;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class VehiclesAPIController extends Controller
{
    public function __construct(){
        $this->middleware('auth:api');
    }
    
    public function getVehicles(Request $request){
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $vehicles = Vehicle::with(['user', 'seat','sacco']);

        $veh = explode(',', str_replace(']', '', str_replace('[', '', $request->vehicles)));
        $all_vehicles = [];

        foreach ($veh as $vehicle) {
            $v = trim($vehicle);
            if($v != ""){
                array_push($all_vehicles, trim($vehicle));
            }
        }

        if($request->sacco > 0){
            $vehicles = $vehicles->where('sacco_id', $request->sacco);
        }

        if($request->seat > 0){
            $vehicles = $vehicles->where('seat_id', $request->seat);
        }
        if(count($all_vehicles)>0){
            $vehicles = $vehicles->whereIn('id', $all_vehicles);
        }
        $vehicles = $vehicles->where(function($query) use($request){
            $query->where('plate', 'LIKE', '%'.$request->search.'%')
            ->orWhere('till_number', 'LIKE', '%'.$request->search.'%')
            ->orWhere('merchant_short_code', 'LIKE', '%'.$request->search.'%');
        });
        $vehicles = $vehicles->skip($offset)->take(20)
        ->orderBy('created_at', 'DESC')->get();
        return response()->json(['vehicles'=>$vehicles]);
    }
    
    public function addVehicle(Request $request)
    {
        if(auth()->user()->can('Add Vehicles') || auth()->user()->can('Edit Vehicles')){
            $validator = Validator::make($request->all(), [
                'id' => 'required|min:0|integer',
                'plate' => 'required|string|unique:vehicles,plate,' . $request->id,
                'fleet_no' => 'string|nullable',
                'till_number' => 'integer|nullable',
                'sacco' => 'string|nullable',
                'seat' => 'required|exists:seats,name',
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
            $sacco = Sacco::where('name', $request->sacco)->first();
            if($sacco != null){
                $vehicle->sacco_id = $sacco->id;
            }
            $seat = Seat::where('name', $request->seat)->first();
            if($seat != null){
                $vehicle->seat_id = $seat->id;
            }
            $vehicle->plate = $request->plate;
            $vehicle->fleet_no = $request->fleet_no;
            $vehicle->till_number = $request->till_number;
            $vehicle->merchant_short_code = $request->merchant_short_code;
            $vehicle->user_id = Auth::user()->id;
            $vehicle->status = $request->status;
            if ($vehicle->save()) {
                if($sacco != null){
                    if(SaccoVehicle::where('vehicle_id', $vehicle->id)->where('sacco_id', $sacco->id)
                    ->where('end_date', null)->count() == 0){
                        $saccoVehicle = new SaccoVehicle;
                        $saccoVehicle->sacco_id = $sacco->id;
                        $saccoVehicle->vehicle_id = $vehicle->id;
                        $saccoVehicle->user_id = Auth::user()->id;
                        $saccoVehicle->start_date = Carbon::now();
                        if($saccoVehicle->save()){
                            SaccoVehicle::where('vehicle_id', $vehicle->id)->where('sacco_id', '<>',$sacco->id)
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
}
