<?php

namespace App\Http\Controllers\APIs\Dashboard\Transactions;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Models\Cash;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CashAPIController extends Controller
{
    use PaginatesResults;


    public function __construct(){
        $this->middleware('auth:sanctum');
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
        $cash = Cash::with(['vehicle.sacco'])
        ->whereBetween('trans_date',[$from_date, $to_date]);
        if($request->sacco > 0){
            $cash = $cash->whereHas('vehicle', function($query) use($request){
                $query->where('sacco_id', $request->sacco);
            });
        }
        if(count($all_vehicles)>0){
            $cash = $cash->whereIn('vehicle_id', $all_vehicles);
        }
        // Only filter when a term was actually typed. Same shape as the M-Pesa
        // search that took the site down: an empty box means LIKE '%%' on three
        // columns OR'd with correlated EXISTS into vehicles and saccos, none of it
        // indexable. `cashes` grows with every trip, so this is the same fault
        // waiting on volume.
        if (filled($request->search)) {
            $cash = $cash->where(function($query)use($request){
                $query->where('trans_id', 'LIKE', '%'.$request->search.'%')
                ->orWhere('firstname', 'LIKE', '%'.$request->search.'%')
                ->orWhere('lastname', 'LIKE', '%'.$request->search.'%')
                ->orWhereHas('vehicle',function($q)use($request){
                    $q->where('plate', 'LIKE', '%'.$request->search.'%');
                })->orWhereHas('vehicle.sacco',function($q)use($request){
                    $q->where('name', 'LIKE', '%'.$request->search.'%');
                });
            });
        }

        if($request->amount != ""){
            $cash = $cash->whereBetween('total_amount', [$request->amount, $request->amount]);
        }
        $__meta = $this->pageMeta($cash, $request, 20);
        $cash = $cash->orderBy('trans_date', 'DESC')->skip($offset)->take(20)->get();
        return response()->json(array_merge(['cash'=>$cash], $__meta));
    }
}
