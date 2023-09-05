<?php

namespace App\Http\Controllers\APIs\Dashboard\Saccos;

use App\Http\Controllers\Controller;
use App\Models\SaccoVehicle;
use Illuminate\Http\Request;

class SaccoVehiclesAPIController extends Controller
{
    
    public function __construct(){
        $this->middleware('auth:api');
    }
    
    public function getSaccoVehicles(Request $request){
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $saccoVehicles = SaccoVehicle::with(['user', 'vehicle','sacco']);
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
}
