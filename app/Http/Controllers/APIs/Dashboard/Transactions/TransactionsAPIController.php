<?php

namespace App\Http\Controllers\APIs\Dashboard\Transactions;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;

class TransactionsAPIController extends Controller
{
    
    public function __construct(){
        $this->middleware('auth:api');
    }

    public function getTransactions(Request $request){
        
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $from_date = $request->date != ""?Carbon::parse($request->date):Carbon::today();
        $to_date = $from_date->copy()->addDays(1);
        
        $vehicles = explode(',', str_replace(']', '', str_replace('[', '', $request->vehicles)));
        $all_vehicles = [];

        foreach ($vehicles as $vehicle) {
            $v = trim($vehicle);
            if($v != ""){
                array_push($all_vehicles, trim($vehicle));
            }
        }

        $transactions = Transaction::with(['mpesa', 'cash', 'vehicle.sacco'])
        ->whereBetween('trans_date',[$from_date, $to_date]);
        if($request->sacco > 0){
            $transactions = $transactions->whereHas('vehicle', function($query) use($request){
                $query->where('sacco_id', $request->sacco);
            });
        }
        //\Log::info(json_encode($all_vehicles));
        if(count($all_vehicles) > 0){
            $transactions = $transactions->whereIn('vehicle_id', $all_vehicles);
        }
        $transactions = $transactions->where(function($q) use($request){
            $q->whereHas('mpesa',function($query)use($request){
                $query->where('TransID', 'LIKE', '%'.$request->search.'%')
                ->orWhere('FirstName', 'LIKE', '%'.$request->search.'%')
                ->orWhere('MiddleName', 'LIKE', '%'.$request->search.'%')
                ->orWhere('LastName', 'LIKE', '%'.$request->search.'%');
            })->orWhereHas('cash',function($query)use($request){
                $query->where('trans_id', 'LIKE', '%'.$request->search.'%')
                ->orWhere('firstname', 'LIKE', '%'.$request->search.'%')
                ->orWhere('lastname', 'LIKE', '%'.$request->search.'%');
            })->orWhereHas('vehicle',function($query)use($request){
                $query->where('plate', 'LIKE', '%'.$request->search.'%');
            })->orWhereHas('vehicle.sacco',function($query)use($request){
                $query->where('name', 'LIKE', '%'.$request->search.'%');
            });
        });
        $mpesaTrans = $transactions->clone();
        $cashTrans = $transactions->clone();
        $mpesa = $mpesaTrans->whereHas('mpesa')->sum('amount');
        $cash = $cashTrans->whereHas('cash')->sum('amount');
        $transactions = $transactions->skip($offset)->take(20)->orderBy('trans_date', 'DESC')->get();
        return response()->json(['transactions'=>$transactions, 'mpesa'=>$mpesa, 'cash'=>$cash]);
    }
}
