<?php

namespace App\Http\Controllers\APIs\Dashboard\Points;

use App\Http\Controllers\Controller;
use App\Models\Point;
use App\Models\RedeemedPoint;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PointsAPIController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api');
    }
    public function getPoints(Request $request)
    {

        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $from_date = $request->date != "" ? Carbon::parse($request->date) : Carbon::today();
        $to_date = $from_date->copy()->addDays(1);

        $points = Point::with('sacco');
        if (auth()->user()->can('View Points')) {
            $points = $points->whereBetween('end_date', [$from_date, $to_date]);
        }else{
            $points = $points->where('phone', auth()->user()->phone);
        }
        $points = $points->orderBy('end_date', 'DESC')->skip($offset)->take(20)->get();
        return response()->json(['points' => $points]);
    }


    public function getRedeemedPoints(Request $request)
    {

        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $from_date = $request->date != "" ? Carbon::parse($request->date) : Carbon::today();
        $to_date = $from_date->copy()->addDays(1);

        $points = RedeemedPoint::with('point.sacco', 'vehicle.seat','vehicle.sacco', 'from', 'to');
        if (auth()->user()->can('View Redeemed Points')) {
            $points = $points->whereBetween('created_at', [$from_date, $to_date]);
            $vehicles = explode(',', str_replace(']', '', str_replace('[', '', $request->vehicles)));
        $all_vehicles = [];

        foreach ($vehicles as $vehicle) {
            $v = trim($vehicle);
            if($v != ""){
                array_push($all_vehicles, trim($vehicle));
            }
        }
        if(count($all_vehicles)>0){
            $points->whereIn('vehicle_id', $all_vehicles);
        }
        }else{
            $points = $points->whereHas('point', function($query) use($request){
                $query->where('phone', auth()->user()->phone);
            });
        }
        $points = $points->orderBy('created_at', 'DESC')->skip($offset)->take(20)->get();
        return response()->json(['redeemed_points' => $points]);
    }
}
