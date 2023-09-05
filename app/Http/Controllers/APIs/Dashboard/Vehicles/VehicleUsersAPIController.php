<?php

namespace App\Http\Controllers\APIs\Dashboard\Vehicles;

use App\Http\Controllers\Controller;
use App\Models\VehicleUser;
use Illuminate\Http\Request;

class VehicleUsersAPIController extends Controller
{
    public function __construct(){
        $this->middleware('auth:api');
    }
    
    public function getVehicleUsers(Request $request){
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $vehicleUsers = VehicleUser::with(['user.roles', 'vehicle.seat','sacco']);
        if($request->sacco > 0){
            $vehicleUsers = $vehicleUsers->where('sacco_id', $request->sacco);
        }
        $vehicleUsers = $vehicleUsers->where(function($q) use($request){
            $q->whereHas('vehicle',function($query) use($request){
                $query->where('plate', 'LIKE', '%'.$request->search.'%')
                ->orWhere('till_number', 'LIKE', '%'.$request->search.'%')
                ->orWhere('merchant_short_code', 'LIKE', '%'.$request->search.'%');
            })->orWhereHas('user',function($query) use($request){
                $query->where('firstname', 'LIKE', '%'.$request->search.'%')
                ->orWhere('lastname', 'LIKE', '%'.$request->search.'%')
                ->orWhere('phone', 'LIKE', '%'.$request->search.'%')
                ->orWhere('email', 'LIKE', '%'.$request->search.'%');
            });
        });
        $vehicleUsers = $vehicleUsers->skip($offset)->take(20)
        ->orderBy('created_at', 'DESC')->get();
        return response()->json(['vehicle_users'=>$vehicleUsers]);
    }
    
}
