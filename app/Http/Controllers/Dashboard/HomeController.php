<?php

namespace App\Http\Controllers\Dashboard;
use App\Http\Controllers\Controller;
use App\Models\Sacco;
use App\Models\SaccoUser;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class HomeController extends Controller
{
    public function __construct(){
        $this->middleware('auth');
    }
    public function index()
    {/*
        $permissions = Permission::get();
        $role = Role::where('name', 'Super Admin')->first();
        if($role != null){
            $role->syncPermissions($permissions);
        }*/
        $sacco = Sacco::find(Auth::user()->sacco_id);
        return view('dashboard.home', @compact('sacco'));
    }

    public function getDashboard(Request $request){
        $sacco = $request->sacco > 0?$request->sacco:"";
        $today = Carbon::today();
        $start_date = $today->copy()->startOfWeek();
        $end_date = $today->copy()->endOfWeek();
        
        $xaxis = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];
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
            $transactions = $transactions->groupby(DB::raw('DAYNAME(trans_date)'))->orderBy(DB::raw('DAYNAME(trans_date)'), 'ASC')->get()->toJson();
        }else if($request->year == 1){
            $transactions = Transaction::select(DB::raw('SUM(amount) as totals'), DB::raw('DAYOFMONTH(trans_date) day'))
            ->whereBetween('trans_date', [$start_date, $end_date]);
            if($sacco > 0){
                $transactions = $transactions->whereHas('vehicle', function($query) use ($sacco){
                    $query->where('sacco_id', $sacco);
                });
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
                $transactions = $transactions->groupby(DB::raw('YEAR(trans_date)'), DB::raw('MONTH(trans_date)'))->orderBy(DB::raw('MONTH(trans_date)'), 'ASC')->get()->toJson();
        }
        
        $ctransactions = Transaction::select(DB::Raw('SUM(CASE WHEN mpesa_id > 0 THEN amount ELSE 0 END) as mpesa, SUM(CASE WHEN cash_id > 0 THEN amount ELSE 0 END) as cash'))
                ->whereBetween('trans_date', [$start_date, $end_date]);
        if($sacco > 0){
            $ctransactions = $ctransactions->whereHas('vehicle', function($query) use ($sacco){
                $query->where('sacco_id', $sacco);
            });
        }
        $ctransactions = $ctransactions->first();
        $mpesa = 0;
        $cash = 0;
        if($ctransactions != null){
            $mpesa = doubleval($ctransactions->mpesa);
            $cash = doubleval($ctransactions->cash);
        }
        return response()->json(['mpesa'=>number_format($mpesa,2), 'cash'=>number_format($cash, 2), 
        'totals'=>number_format($mpesa+$cash, 2), 'transactions'=>$transactions, 'cashes'=>$cash,
        'mpesas'=>$mpesa, "xaxis"=>json_encode($xaxis)]);
    }
}
