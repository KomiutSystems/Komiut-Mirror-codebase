<?php

namespace App\Http\Controllers\APIs\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HomeAPIController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function getDashboard(Request $request){
        $sacco = $request->sacco > 0?$request->sacco:"";
        $today = Carbon::today();
        $start_date = $today->copy()->startOfWeek();
        $end_date = $today->copy()->endOfWeek();

        $vehicles = explode(',', str_replace(']', '', str_replace('[', '', $request->vehicles)));
        $all_vehicles = [];

        foreach ($vehicles as $vehicle) {
            $v = trim($vehicle);
            if($v != ""){
                array_push($all_vehicles, trim($vehicle));
            }
        }
        $xaxis = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
        $months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];

        if($request->year == 1){
            $start_date = $today->copy()->startOfMonth();
            $end_date = $today->copy()->endOfMonth();
            $start_day = intval($start_date->format('d'));
            $end_day = intval($end_date->format('d'));
            $xaxis = [];
            for($i=$start_day; $i < $end_day; $i++){
                array_push($xaxis, sprintf('%02d', $i));
            }
        }

        if($request->year == 2){
            $start_date = $today->copy()->startOfMonth()->subMonths(2);
            $end_date = $today->copy()->endOfMonth();
            $xaxis = [];
            $index = intval($start_date->format('m')) - 1;
            for($i=0; $i < 3; $i++){
                if($index > 11){
                    $index = 0;
                }
                array_push($xaxis, $months[$index]);
                $index++;
            }
        }
        if($request->year == 3){
            $start_date = $today->copy()->startOfMonth()->subMonths(5);
            $end_date = $today->copy()->endOfMonth();$xaxis = [];
            $index = intval($start_date->format('m')) - 1;
            for($i=0; $i < 6; $i++){
                if($index > 11){
                    $index = 0;
                }
                array_push($xaxis, $months[$index]);
                $index++;
            }
        }
        if($request->year == 4){
            $start_date = $today->copy()->startOfYear();
            $end_date = $today->copy()->endOfMonth();$xaxis = [];

            $index = intval($start_date->format('m')) - 1;
            for($i=0; $i < 12; $i++){
                if($index > 11){
                    $index = 0;
                }
                array_push($xaxis, $months[$index]);
                $index++;
            }
        }
        if($request->year == 0){
            $transactions = Transaction::select(DB::raw('SUM(amount) as totals'), DB::raw('DAYNAME(trans_date) day'))
            ->whereBetween('trans_date', [$start_date, $end_date]);
            if($sacco > 0){
                $transactions = $transactions->whereHas('vehicle', function($query) use ($sacco){
                    $query->where('sacco_id', $sacco);
                });
            }
            if(count($all_vehicles)>0){
                $transactions = $transactions->whereIn('vehicle_id', $all_vehicles);
            }
            $transactions = $transactions->groupby(DB::raw('DAYNAME(trans_date)'))->orderBy(DB::raw('DAYNAME(trans_date)'), 'ASC')->get()->toJson();
        }else if($request->year == 1){
            $transactions = Transaction::select(DB::raw('SUM(amount) as totals'), DB::raw('DAYOFMONTH(trans_date) day'))
            ->whereBetween('trans_date', [$start_date, $end_date]);
            if($sacco > 0){
                $transactions = $transactions->whereHas('vehicle', function($query) use ($sacco){
                    $query->where('sacco_id', $sacco);
                });
            }
            if(count($all_vehicles)>0){
                $transactions = $transactions->whereIn('vehicle_id', $all_vehicles);
            }
            $transactions = $transactions->groupby(DB::raw('DAYOFMONTH(trans_date)'))->orderBy(DB::raw('DAYOFMONTH(trans_date)'), 'ASC')->get()->toJson();
        } else {
            $transactions = Transaction::select(DB::raw('SUM(amount) as totals'), DB::raw('YEAR(trans_date) year, MONTH(trans_date) month'))
                ->whereBetween('trans_date', [$start_date, $end_date]);
                if($sacco > 0){
                    $transactions = $transactions->whereHas('vehicle', function($query) use ($sacco){
                        $query->where('sacco_id', $sacco);
                    });
                }
                if(count($all_vehicles)>0){
                    $transactions = $transactions->whereIn('vehicle_id', $all_vehicles);
                }
                $transactions = $transactions->groupby(DB::raw('YEAR(trans_date)'), DB::raw('MONTH(trans_date)'))->orderBy(DB::raw('MONTH(trans_date)'), 'ASC')->get()->toJson();
        }

        $ctransactions = Transaction::select(DB::Raw('SUM(CASE WHEN mpesa_id > 0 THEN amount ELSE 0 END) as mpesa, SUM(CASE WHEN cash_id > 0 THEN amount ELSE 0 END) as cash'))
                ->whereBetween('trans_date', [$start_date, $end_date]);
        if($sacco > 0){
            $ctransactions = $ctransactions->whereHas('vehicle', function($query) use ($sacco){
                $query->where('sacco_id', $sacco);
            });
        }
        if(count($all_vehicles)>0){
            $ctransactions = $ctransactions->whereIn('vehicle_id', $all_vehicles);
        }
        $ctransactions = $ctransactions->first();
        $mpesa = 0;
        $cash = 0;
        if($ctransactions != null){
            $mpesa = doubleval($ctransactions->mpesa);
            $cash = doubleval($ctransactions->cash);
        }
        return response()->json(['mpesa'=>$mpesa, 'cash'=>$cash,
        'totals'=>$mpesa+$cash, 'transactions'=>$transactions,"xaxis"=>json_encode($xaxis)]);
    }

}
