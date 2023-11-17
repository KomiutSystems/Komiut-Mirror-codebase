<?php

namespace App\Http\Controllers\Dashboard\Summaries;

use DB;
use App\Models\Sacco;
use App\Http\Controllers\Controller;
use App\Models\Summary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Yajra\DataTables\DataTables;
use Carbon\Carbon;

class SummaryController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(['permission:View Summaries']);
    }
    public function index(){
        $sacco = Sacco::find(Auth::user()->sacco_id);
        return view('dashboard.summaries.summaries', @compact('sacco'));
    }
    public function getSummaries(Request $request){
        $from_date = Carbon::parse($request->from_date);
        $to_date = Carbon::parse($request->to_date);
        $summaries = Summary::select('vehicle_id', DB::raw('SUM(mpesa_amount) as mpesa_amount'), DB::raw('SUM(mpesa_txn) as mpesa_txn'), 
        DB::raw('SUM(cash_amount) as cash_amount'), DB::raw('SUM(cash_txn) as cash_txn'), 
        DB::raw('SUM(mpesa_amount+cash_amount) as totals'), DB::raw('SUM(mpesa_txn+cash_txn) as total_txn'))
        ->with(['vehicle.sacco'])
        ->whereBetween('trans_date',[$from_date, $to_date])->groupBy('vehicle_id');
        if($request->sacco > 0){
            $summaries = $summaries->whereHas('vehicle', function($query) use($request){
                $query->where('sacco_id', $request->sacco);
            });
        }
        $transactions = $summaries->where(function($q) use($request){
            $q->orWhereHas('vehicle',function($query)use($request){
                $query->where('plate', 'LIKE', '%'.$request->search.'%');
            })->orWhereHas('vehicle.sacco',function($query)use($request){
                $query->where('name', 'LIKE', '%'.$request->search.'%');
            });
        })->orderBy(DB::raw('SUM(mpesa_amount+cash_amount)'), 'DESC');

        return DataTables::of($transactions)
        ->addColumn("transdate", function($row){
            return Carbon::parse($row->trans_date)->format('d M, Y');
        })
        ->editColumn("mpesa_amount", function($row){
            return number_format($row->mpesa_amount, 2,'.',',');
        })
        ->editColumn("mpesa_txn", function($row){
            return number_format($row->mpesa_txn, 0,'.',',');
        })
        ->editColumn("cash_amount", function($row){
            return number_format($row->cash_amount, 2,'.',',');
        })
        ->editColumn("cash_txn", function($row){
            return number_format($row->cash_txn, 0,'.',',');
        })
        ->editColumn("totals", function($row){
            return number_format($row->totals, 2,'.',',');
        })
        ->editColumn("total_txn", function($row){
            return number_format($row->total_txn, 0,'.',',');
        })->addIndexColumn()->escapeColumns([])->make();
    }
    public function getSummariesCards(Request $request){
        $sacco = $request->sacco > 0?$request->sacco:"";
        $from_date = $request->from_date != ""?Carbon::parse($request->from_date):Carbon::today();
        $to_date = $request->to_date != ""?Carbon::parse($request->to_date):Carbon::now();
        
        $transactions = Summary::select(DB::Raw('SUM(mpesa_amount) as mpesa, SUM(cash_amount) as cash'))
                ->whereBetween('trans_date', [$from_date, $to_date]);
        if($sacco > 0){
            $transactions = $transactions->whereHas('vehicle', function($query) use ($sacco){
                $query->where('sacco_id', $sacco);
            });
        }
        $transactions = $transactions->where(function($q) use($request){
            $q->whereHas('vehicle',function($query)use($request){
                $query->where('plate', 'LIKE', '%'.$request->search.'%');
            });
        });
        $transactions = $transactions->first();
        $mpesa = 0;
        $cash = 0;
        if($transactions != null){
            $mpesa = doubleval($transactions->mpesa);
            $cash = doubleval($transactions->cash);
        }
        return response()->json(['mpesa'=>number_format($mpesa,2), 'cash'=>number_format($cash, 2), 
        'totals'=>number_format($mpesa+$cash, 2)]);
    }
}
