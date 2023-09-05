<?php

namespace App\Http\Controllers\APIs\Dashboard\Transactions;

use App\Http\Controllers\Controller;
use App\Models\Mpesa;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;

class MpesaAPIController extends Controller
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

        $mpesa = Mpesa::with(['transaction.vehicle.sacco'])
        ->whereBetween('TransTime', [$from_date, $to_date]);
        if($request->sacco > 0){
            $mpesa = $mpesa->whereHas('transaction.vehicle', function($query) use($request){
                $query->where('sacco_id', $request->sacco);
            });
        }
        $mpesa = $mpesa->where(function($query)use($request){
            $query->where('TransID', 'LIKE', '%'.$request->search.'%')
            ->orWhere('FirstName', 'LIKE', '%'.$request->search.'%')
            ->orWhere('MiddleName', 'LIKE', '%'.$request->search.'%')
            ->orWhere('LastName', 'LIKE', '%'.$request->search.'%');
            $query->orWhereHas('transaction.vehicle',function($q)use($request){
                $q->where('plate', 'LIKE', '%'.$request->search.'%');
            })->orWhereHas('transaction.vehicle.sacco',function($q)use($request){
                $q->where('name', 'LIKE', '%'.$request->search.'%');
            });
        })->orderBy('TransTime', 'DESC')->skip($offset)->take(20)->get();
        return response()->json(['mpesa'=>$mpesa]);
    }
}
