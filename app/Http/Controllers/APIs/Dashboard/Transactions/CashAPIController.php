<?php

namespace App\Http\Controllers\APIs\Dashboard\Transactions;

use App\Http\Controllers\Controller;
use App\Models\Cash;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CashAPIController extends Controller
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
    $cash = Cash::with(['vehicle.sacco'])
        ->whereBetween('trans_date',[$from_date, $to_date]);
        if($request->sacco > 0){
            $cash = $cash->whereHas('vehicle', function($query) use($request){
                $query->where('sacco_id', $request->sacco);
            });
        }
        $cash = $cash->where(function($query)use($request){
            $query->where('trans_id', 'LIKE', '%'.$request->search.'%')
            ->orWhere('firstname', 'LIKE', '%'.$request->search.'%')
            ->orWhere('lastname', 'LIKE', '%'.$request->search.'%')
            ->orWhereHas('vehicle',function($q)use($request){
                $q->where('plate', 'LIKE', '%'.$request->search.'%');
            })->orWhereHas('vehicle.sacco',function($q)use($request){
                $q->where('name', 'LIKE', '%'.$request->search.'%');
            });
        })->orderBy('trans_date', 'DESC')->skip($offset)->take(20)->get();
        return response()->json(['cash'=>$cash]);
    }
}
