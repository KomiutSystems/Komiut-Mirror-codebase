<?php

namespace App\Http\Controllers\APIs\Dashboard\Summaries;

use App\Http\Controllers\Controller;
use App\Models\Summary;
use Carbon\Carbon;
use Illuminate\Http\Request;
use DB;

class SummariesAPIController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api');
    }
    public function getSummaries(Request $request)
    {
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
        $summaries = Summary::select(
            'vehicle_id',
            DB::raw('SUM(mpesa_amount) as mpesa_amount'),
            DB::raw('SUM(mpesa_txn) as mpesa_txn'),
            DB::raw('SUM(cash_amount) as cash_amount'),
            DB::raw('SUM(cash_txn) as cash_txn'),
            DB::raw('SUM(mpesa_amount+cash_amount) as totals'),
            DB::raw('SUM(mpesa_txn+cash_txn) as total_txn'),
            DB::raw('SUM(expense_fee_amount) as expense_fee_amount')
        )
            ->with(['vehicle.sacco', 'vehicle.seat'])
            ->whereBetween('trans_date', [$from_date, $to_date])->groupBy('vehicle_id');
        if ($request->sacco > 0) {
            $summaries = $summaries->whereHas('vehicle', function ($query) use ($request) {
                $query->where('sacco_id', $request->sacco);
            });
        }
        if(count($all_vehicles) > 0){
            $summaries = $summaries->whereIn('vehicle_id', $all_vehicles);
        }
        $summaries = $summaries->where(function ($q) use ($request) {
            $q->orWhereHas('vehicle', function ($query) use ($request) {
                $query->where('plate', 'LIKE', '%' . $request->search . '%');
            })->orWhereHas('vehicle.sacco', function ($query) use ($request) {
                $query->where('name', 'LIKE', '%' . $request->search . '%');
            });
        });

        $mpesaSummaries = $summaries->clone();
        $cashSummaries = $summaries->clone();
        $mpesa = $mpesaSummaries->sum('mpesa_amount');
        $cash = $cashSummaries->sum('cash_amount');

        $summaries = $summaries->skip($offset)->take(20)->orderBy(DB::raw('SUM(mpesa_amount+cash_amount)'), 'DESC');
        return response()->json(['summaries'=>$summaries, 'mpesa'=>$mpesa, 'cash'=>$cash]);
    }
}
