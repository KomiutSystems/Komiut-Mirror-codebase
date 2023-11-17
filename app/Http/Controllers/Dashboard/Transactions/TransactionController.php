<?php

namespace App\Http\Controllers\Dashboard\Transactions;

use App\Http\Controllers\Controller;
use App\Models\Sacco;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Yajra\DataTables\DataTables;
use DB;

class TransactionController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(['permission:View Transactions']);
    }
    public function index(){
        $sacco = Sacco::find(Auth::user()->sacco_id);
        return view('dashboard.transactions.transactions', @compact('sacco'));
    }
    public function getTransactions(Request $request){
        $from_date = Carbon::parse($request->from_date);
        $to_date = Carbon::parse($request->to_date);
        $transactions = Transaction::with(['mpesa', 'cash', 'vehicle.sacco'])
        ->whereBetween('trans_date',[$from_date, $to_date]);
        if($request->sacco > 0){
            $transactions = $transactions->whereHas('vehicle', function($query) use($request){
                $query->where('sacco_id', $request->sacco);
            });
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
            })/*->orWhereHas('vehicle.sacco',function($query)use($request){
                $query->where('name', 'LIKE', '%'.$request->search.'%');
            })*/;
        })->orderBy('trans_date', 'DESC');

        return DataTables::of($transactions)
        ->editColumn('created_at', function ($row) {
            return $row->mpesa != null?$row->mpesa->TransTime:$row->cash->trans_date;//return Carbon::parse($row->created_at)->diffForHumans();
        })->addColumn("transid", function($row){
            return $row->mpesa != null?$row->mpesa->TransID: $row->cash->trans_id;
        })->addColumn("name", function($row){
            return $row->mpesa != null?$row->mpesa->FirstName.' '.$row->mpesa->MiddleName.' '.$row->mpesa->LastName:
            $row->cash->firstname.' '.$row->cash->lastname;
        })->addColumn("transdate", function($row){
            $date = $row->mpesa != null?$row->mpesa->TransTime:$row->cash->trans_date;
            return Carbon::parse($date)->format('d M, Y h:i A');
        })->addColumn("phone", function($row){
            return $row->mpesa != null?$row->mpesa->MSISDN:$row->cash->phone;
        })->addIndexColumn()->escapeColumns([])->make();
    }
    public function getTransactionsCard(Request $request){
        $sacco = $request->sacco > 0?$request->sacco:"";
        $from_date = $request->from_date != ""?Carbon::parse($request->from_date):Carbon::today();
        $to_date = $request->to_date != ""?Carbon::parse($request->to_date):Carbon::now();
        
        $transactions = Transaction::select(DB::Raw('SUM(CASE WHEN mpesa_id > 0 THEN amount ELSE 0 END) as mpesa, SUM(CASE WHEN cash_id > 0 THEN amount ELSE 0 END) as cash'))
                ->whereBetween('trans_date', [$from_date, $to_date]);
        if($sacco > 0){
            $transactions = $transactions->whereHas('vehicle', function($query) use ($sacco){
                $query->where('sacco_id', $sacco);
            });
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
            })/*->orWhereHas('vehicle.sacco',function($query)use($request){
                $query->where('name', 'LIKE', '%'.$request->search.'%');
            })*/;
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
