<?php

namespace App\Http\Controllers\Dashboard\Transactions;

use App\Http\Controllers\Controller;
use App\Models\Sacco;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Yajra\DataTables\DataTables;

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
            })->orWhereHas('vehicle.sacco',function($query)use($request){
                $query->where('name', 'LIKE', '%'.$request->search.'%');
            });
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
}
