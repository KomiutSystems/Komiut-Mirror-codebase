<?php

namespace App\Http\Controllers\APIs\Dashboard\Vehicles;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use Illuminate\Http\Request;

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
        if($request->sacco > 0){
            $vehicles = $vehicles->where('sacco_id', $request->sacco);
        }

        if($request->seat > 0){
            $vehicles = $vehicles->where('seat_id', $request->seat);
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
}
