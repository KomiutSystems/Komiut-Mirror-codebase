<?php

namespace App\Http\Controllers\Dashboard\Transactions;

use App\Http\Controllers\Controller;
use App\Imports\ExcelMpesaImport;
use App\Models\Mpesa;
use App\Models\Sacco;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Yajra\DataTables\DataTables;

class MpesaController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(['permission:View Transactions']);
    }
    public function index(){
        $sacco = Sacco::find(Auth::user()->sacco_id);
        return view('dashboard.transactions.mpesas', @compact('sacco'));
    }
    public function getMpesa(Request $request){
        
        $from_date = Carbon::parse($request->from_date);
        $to_date = Carbon::parse($request->to_date);
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
        })->orderBy('TransTime', 'DESC');
        return DataTables::of($mpesa)
        ->editColumn('created_at', function ($row) {
            return Carbon::parse($row->created_at)->diffForHumans();
        })->editColumn('TransTime', function ($row) {
            return Carbon::parse($row->TransTime)->format('d M, Y h:i A');
        })->addIndexColumn()->escapeColumns([])->make();
    }
    public function import(Request $request){
        if(auth()->user()->can('Edit Transactions')){
            if (\Maatwebsite\Excel\Facades\Excel::import(new ExcelMpesaImport,
                $request->file('excel_file'))) {
                return back()->with("success", "Mpesa Excel Sheet uploaded successfully!");
            } else {
                return back()->with("error", "Unable to upload mpesa excel sheet!");
            }
        }else{
            return back()->with("error", "You do not have permissions for uploading transactions");
        }
    }
}
