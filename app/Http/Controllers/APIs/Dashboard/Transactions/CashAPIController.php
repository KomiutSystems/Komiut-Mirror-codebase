<?php

namespace App\Http\Controllers\APIs\Dashboard\Transactions;

use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Concerns\ScopesToOwnedVehicles;
use App\Http\Controllers\Controller;
use App\Models\Cash;
use App\Services\Sql\LikeSql;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CashAPIController extends Controller
{
    use PaginatesResults;
    use ScopesToOwnedVehicles;


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

        // The OWNERSHIP boundary, and the reason this controller is in the
        // change at all: `transactions/cash` is gated on 'View Transactions',
        // which the Investor role holds, and Cash carries only SaccoScope — so
        // this endpoint served the whole SACCO's cash takings to an investor
        // exactly as its M-Pesa sibling did.
        //
        // It is the other tab of the same Transactions screen, and the KES
        // 2,619,683 NICCO took on the last full day is the two of them added
        // together. Narrowing the M-Pesa tab alone would have moved the leak
        // one click to the left rather than closing it.
        //
        // Ungated: an empty array compiles to `0 = 1`, so an investor with no
        // open assignment sees nothing.
        $ownedVehicleIds = $this->ownedVehicleIds();
        if ($ownedVehicleIds !== null) {
            $cash = $cash->whereIn('vehicle_id', $ownedVehicleIds);
        }

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
                $query->where('trans_id', LikeSql::op(), '%'.$request->search.'%')
                ->orWhere('firstname', LikeSql::op(), '%'.$request->search.'%')
                ->orWhere('lastname', LikeSql::op(), '%'.$request->search.'%')
                ->orWhereHas('vehicle',function($q)use($request){
                    $q->where('plate', LikeSql::op(), '%'.$request->search.'%');
                })->orWhereHas('vehicle.sacco',function($q)use($request){
                    $q->where('name', LikeSql::op(), '%'.$request->search.'%');
                });
            });
        }

        if($request->amount != ""){
            $cash = $cash->whereBetween('total_amount', [$request->amount, $request->amount]);
        }
        $__meta = $this->pageMeta($cash, $request, 20);
        // id breaks ties: trans_date is not unique, and rows sharing one come
        // back in plan order — the way a row lands on two pages or none.
        $cash = $cash->orderBy('trans_date', 'DESC')->orderBy('id', 'DESC')
            ->skip($offset)->take(20)->get();
        return response()->json(array_merge(['cash'=>$cash], $__meta));
    }
}
