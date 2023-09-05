<?php

namespace App\Http\Controllers\APIs\Dashboard\Saccos;

use App\Http\Controllers\Controller;
use App\Models\SaccoRoute;
use Illuminate\Http\Request;

class SaccoRoutesAPIController extends Controller
{
    public function __construct(){
        $this->middleware('auth:api');
    }
    
    public function getSaccoRoutes(Request $request){
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $saccoRoutes = SaccoRoute::with(['route.from', 'route.to','sacco']);
        if($request->sacco > 0){
            $saccoRoutes = $saccoRoutes->where('sacco_id', $request->sacco);
        }if($request->from > 0){
            $saccoRoutes = $saccoRoutes->whereHas('route',function($query)use( $request){
                $query->where('from_id', $request->from);
            });
        }if($request->to > 0){
            $saccoRoutes = $saccoRoutes->whereHas('route',function($query)use( $request){
                $query->where('to_id', $request->to);
            });
        }
        /*
        $saccoRoutes = $saccoRoutes->whereHas('vehicle',function($query) use($request){
            $query->where('plate', 'LIKE', '%'.$request->search.'%')
            ->orWhere('till_number', 'LIKE', '%'.$request->search.'%')
            ->orWhere('merchant_short_code', 'LIKE', '%'.$request->search.'%');
        });*/
        $saccoRoutes = $saccoRoutes->skip($offset)->take(20)
        ->orderBy('created_at', 'DESC')->get();
        return response()->json(['sacco_routes'=>$saccoRoutes]);
    }
}
