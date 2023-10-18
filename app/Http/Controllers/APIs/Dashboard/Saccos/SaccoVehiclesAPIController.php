<?php

namespace App\Http\Controllers\APIs\Dashboard\Saccos;

use App\Http\Controllers\Controller;
use App\Models\SaccoVehicle;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class SaccoVehiclesAPIController extends Controller
{
    
    public function __construct(){
        $this->middleware('auth:api');
    }
    
    public function getSaccoVehicles(Request $request){
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $saccoVehicles = SaccoVehicle::with(['user', 'vehicle.seat','vehicle.sacco','sacco']);
        if($request->sacco > 0){
            $saccoVehicles = $saccoVehicles->where('sacco_id', $request->sacco);
        }
        $saccoVehicles = $saccoVehicles->whereHas('vehicle',function($query) use($request){
            $query->where('plate', 'LIKE', '%'.$request->search.'%')
            ->orWhere('till_number', 'LIKE', '%'.$request->search.'%')
            ->orWhere('merchant_short_code', 'LIKE', '%'.$request->search.'%');
        });
        $saccoVehicles = $saccoVehicles->skip($offset)->take(20)
        ->orderBy('created_at', 'DESC')->get();
        return response()->json(['sacco_vehicles'=>$saccoVehicles]);
    }
    
    public function addVehicle(Request $request)
    {
        if(auth()->user()->can('Edit Sacco Vehicles') || auth()->user()->can('Add Sacco Vehicles')){
            $validator = Validator::make($request->all(), [
                'id' => 'required|integer|min:0',
                'sacco' => 'required|exists:saccos,id',
                'vehicle' => 'required|exists:vehicles,id',
                'status' => 'required|integer|min:0|max:1'
            ]);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }
            
            $saccoVehicle = SaccoVehicle::where('vehicle_id', $request->vehicle)->where('sacco_id', $request->sacco)
            ->where('end_date', null)->first();
            if($saccoVehicle == null){
                SaccoVehicle::where('user_id', $request->member)->where('id', '<>', $request->id)
                ->update(['end_date'=>Carbon::now()]);
                
                $saccoVehicle = new SaccoVehicle;
                if ($request->id > 0) {
                    $saccoVehicle = SaccoVehicle::findOrFail($request->id);
                }else{
                    $saccoVehicle->start_date = Carbon::now();
                }
            }
            $saccoVehicle->sacco_id = $request->sacco;
            $saccoVehicle->vehicle_id = $request->vehicle;
            $saccoVehicle->status = $request->status;
            $saccoVehicle->user_id = Auth::user()->id;

            if ($saccoVehicle->save()) {
                SaccoVehicle::where('user_id', $request->member)->where('id', '<>',$saccoVehicle->id)
                ->where('end_date', null)->update(['end_date'=>Carbon::now(), 'status'=>0]);
                Vehicle::where('id', $request->vehicle)->update(['sacco_id'=>$request->sacco]);
                return response()->json(['success' => 'Vehicle updated successfully!']);
            } else {
                return response()->json(['error' => 'Unable to update Vehicle'], 401);
            }
        }else {
            return response()->json(['error' => 'Permissions to Add/Edit Sacco Vehicle Denied'], 401);
        }

    }
    
}
