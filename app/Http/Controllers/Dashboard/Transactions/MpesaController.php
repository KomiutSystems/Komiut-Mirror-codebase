<?php

namespace App\Http\Controllers\Dashboard\Transactions;

use App\Http\Controllers\Controller;
use App\Imports\ExcelMpesaImport;
use App\Models\Mpesa;
use App\Models\Sacco;
use App\Models\Transaction;
use App\Models\VehicleUser;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Yajra\DataTables\DataTables;
use DB;

class MpesaController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(['permission:View Transactions']);
    }

    public function index()
    {

        $sacco = Sacco::find(Auth::user()->sacco_id);
        return view('dashboard.transactions.mpesas', @compact('sacco'));
    }
    public function getMpesa(Request $request)
    {

        $from_date = Carbon::parse($request->date);
        $to_date = Carbon::parse($request->date)->addDay();
        $transactions = Transaction::has('mpesa')->with(['mpesa', 'vehicle.sacco', 'direct_line_claim'])
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
            $q->orWhereHas('vehicle',function($query)use($request){
                $query->where('plate', 'LIKE', $request->search.'%');
            });
        })->orderBy('trans_date', 'DESC');

        return DataTables::of($transactions)
        ->editColumn('created_at', function ($row) {
            return $row->mpesa->TransTime;
        })->addColumn("transid", function($row){
            return $row->mpesa != null?$row->mpesa->TransID: $row->cash->trans_id;
        })->addColumn("name", function($row){
            return $row->mpesa->FirstName.' '.$row->mpesa->MiddleName.' '.$row->mpesa->LastName;
        })->addColumn("transdate", function($row){
            $date = $row->mpesa->TransTime;
            return Carbon::parse($date)->format('d M, Y h:i A');
        })->addColumn("phone", function($row){
            return substr($row->mpesa->MSISDN,0,12);
        })->addIndexColumn()->escapeColumns([])->make();
    }
    public function import(Request $request)
    {
        $request->validate([
            'vehicle' => 'required|integer|exists:vehicles,id',
            'excel_file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        if (auth()->user()->can('Edit Transactions')) {
            if (
                \Maatwebsite\Excel\Facades\Excel::import(
                    new ExcelMpesaImport($request->vehicle),
                    $request->file('excel_file')
                )
            ) {
                return back()->with("success", "Mpesa Excel Sheet uploaded successfully!");
            } else {
                return back()->with("error", "Unable to upload mpesa excel sheet!");
            }
        } else {
            return back()->with("error", "You do not have permissions for uploading transactions");
        }
    }
}
