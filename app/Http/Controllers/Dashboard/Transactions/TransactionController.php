<?php

namespace App\Http\Controllers\Dashboard\Transactions;

use App\Http\Controllers\Controller;
use App\Models\Sacco;
use App\Models\Transaction;
use App\Models\VehicleUser;
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
        $transactions = Transaction::with(['mpesa', 'cash', 'vehicle.sacco', 'direct_line_claim'])
        ->whereBetween('trans_date',[$from_date, $to_date]);
        if($request->sacco > 0){
            $transactions = $transactions->whereHas('vehicle', function($query) use($request){
                $query->where('sacco_id', $request->sacco);
            });
        }

        $vehicles = VehicleUser::where('user_id', auth()->user()->id)
                ->where('status', true)->pluck('vehicle_id');
                if(count($vehicles)>0){
                    $transactions = $transactions->whereIn('vehicle_id', $vehicles);
                }
        $transactions = $transactions->where(function($q) use($request){
            $q->whereHas('mpesa',function($query)use($request){
                $query->where('TransID', 'LIKE', '%'.$request->search.'%')
                ->orWhere(DB::Raw('CONCAT(FirstName, " ", MiddleName, " ", LastName)'), 'LIKE', '%'.$request->search.'%')
                ->orWhere('MSISDN', 'LIKE', '%'.$request->search.'%');
            })->orWhereHas('cash',function($query)use($request){
                $query->where('trans_id', 'LIKE', '%'.$request->search.'%')
                ->orWhere(DB::Raw('concat(firstname, " ", lastname)'), 'LIKE', '%'.$request->search.'%')
                ->orWhere('phone', 'LIKE', '%'.$request->search.'%');
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
            return $row->mpesa != null?substr($row->mpesa->MSISDN,0,12):substr($row->cash->phone,0,12);
        })->addColumn('action', function ($row) {
            $actionBtn = '<div style="white-space: nowrap;" class="text-end">' .
            '<span class="d-none id">' . ($row->direct_line_claim != null?$row->direct_line_claim->id:"0") . '</span>' .
            '<span class="d-none transaction_id">' . $row->id . '</span>' .
                '<span class="d-none name">' . ($row->mpesa !=null?$row->mpesa->FirstName.' '.$row->mpesa->MiddleName.' '.$row->mpesa->LastName:$row->cash->firstname.' '.$row->cash->lastname) . '</span>' .
                '<span class="d-none phone">' . '0' .($row->mpesa != null?substr($row->mpesa->MSISDN,3):$row->cash->phone) . '</span>' .
                '<span class="d-none vehicle_id">' . $row->vehicle_id . '</span>' .
                '<span class="d-none vehicle">' . ($row->vehicle != null ? $row->vehicle->plate . '( ' . $row->vehicle->till_number . ' | ' . $row->vehicle->merchant_short_code . ')' : '') . '</span>' .
                '<span class="d-none sacco">' . ($row->vehicle != null ? ($row->vehicle->sacco != null?$row->vehicle->sacco->name:'-') : '-') . '</span>' .
                '<span class="d-none travel_date">' . $row->trans_date . '</span>' .
                '<span class="d-none status">1</span>';
            if (auth()->user()->can('Edit Transactions'))
                $actionBtn .= '<button class="btn-edit btn btn-primary btn-sm" data-toggle="modal" data-target="#vehicleModal" '.($row->direct_line_claim!=null?'disabled':'').'><i class="fas fa-edit"></i> Add Claim</button> ';
            $actionBtn .= '</div>';
            return $actionBtn;
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
                ->orWhere(DB::Raw('CONCAT(FirstName, " ", MiddleName, " ", LastName)'), 'LIKE', '%'.$request->search.'%')
                ->orWhere('MSISDN', 'LIKE', '%'.$request->search.'%');
            })->orWhereHas('cash',function($query)use($request){
                $query->where('trans_id', 'LIKE', '%'.$request->search.'%')
                ->orWhere(DB::Raw('CONCAT(firstname, " ", lastname)'), 'LIKE', '%'.$request->search.'%')
                ->orWhere('phone', 'LIKE', '%'.$request->search.'%');
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
