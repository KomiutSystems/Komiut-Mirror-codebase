<?php

namespace App\Http\Controllers\Dashboard\Points;

use App\Http\Controllers\Controller;
use App\Models\MpesaBookingCallback;
use App\Models\Point;
use App\Models\PointSetting;
use App\Models\PointTransaction;
use App\Models\Sacco;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Yajra\DataTables\DataTables;
use DB;

class PointsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }


    public function index()
    {
        $mpesas = MpesaBookingCallback::all();
        foreach($mpesas as $mpesa){
            $pointTransaction = PointTransaction::where('mpesa_booking_callback_id', $mpesa->id)->first();
            if($pointTransaction == null){
                $pointTransaction = new PointTransaction();
            }
            $pointTransaction->mpesa_booking_callback_id = $mpesa->id;
            $pointTransaction->points = $mpesa->amount/100;
            $pointTransaction->trans_date = $mpesa->created_at;
            $pointTransaction->save();
        }
        $sacco = Sacco::find(Auth::user()->sacco_id);
        return view('dashboard.points.points', @compact('sacco'));
    }

    public function getPoints(Request $request){$dates = explode('to', $request->date);
        $start_date = Carbon::parse($dates[0]);
        $end_date = "";
        if(count($dates) > 1){
            $end_date = Carbon::parse($dates[1])->addDay();
        }else{
            $end_date = $start_date->copy()->addDay();
        }

        $transactions = Transaction::select(DB::Raw('CONCAT(mpesas.FirstName, " ",mpesas.MiddleName, " ", mpesas.LastName) as mpesa_name, CONCAT(cashes.firstname, " ",cashes.lastname) as cash_name'),
        'mpesas.MSISDN','cashes.phone', DB::Raw('FORMAT(SUM(points),2) as points'),'saccos.name as sacco')->leftJoin('mpesas', 'mpesas.id', 'transactions.mpesa_id')->
        leftJoin('cashes', 'cashes.id', 'transactions.cash_id')->join('vehicles', 'vehicles.id', 'transactions.vehicle_id')->leftJoin('saccos', 'saccos.id', 'vehicles.sacco_id')
        ->groupBy(DB::Raw('CONCAT(mpesas.FirstName, " ",mpesas.MiddleName, " ", mpesas.LastName), CONCAT(cashes.firstname, " ",cashes.lastname)'),'mpesas.MSISDN','cashes.phone','saccos.name')
        ->whereBetween('transactions.trans_date', [$start_date, $end_date])->where('redeemed', 1);
        if($request->sacco > 0){
            $transactions = $transactions->where('vehicles.sacco_id', $request->sacco);
        }
        if(!auth()->user()->can('View Points')){
            $transactions = $transactions->where(function($query){
                $query->where('cashes.phone', auth()->user()->phone)
                ->orWhere('mpesas.MSISDN', auth()->user()->phone);
            });
        }
        $transactions = $transactions->where(function($q) use($request){
            $q->where(DB::Raw('CONCAT(mpesas.FirstName, " ",mpesas.MiddleName, " ", mpesas.LastName)'),'LIKE', '%'.$request->search.'%')
                ->orWhere('mpesas.MSISDN', 'LIKE', '%'.$request->search.'%')
                ->orWhere('cashes.phone', 'LIKE', '%'.$request->search.'%');
            })->orderBy(DB::Raw('SUM(points)'), 'DESC');

        return DataTables::of($transactions)
        ->addColumn('name', function ($row) {
            return $row->mpesa_name != null?$row->mpesa_name:$row->cash_name;//return Carbon::parse($row->created_at)->diffForHumans();
        })
        ->addColumn('phone', function ($row) {
            return $row->MSISDN != null?$row->MSISDN:$row->phone;//return Carbon::parse($row->created_at)->diffForHumans();
        })->addIndexColumn()->escapeColumns([])->make();
    }
    /*public function getPoints(Request $request)
    {
        $start_date = Carbon::parse($request->date);
        $end_date = $start_date->copy()->addDay();
        $points = Point::with('sacco')->whereBetween('end_date', [$start_date, $end_date])->orderBy('points', 'desc');
        if ($request->sacco > 0) {
            $points = $points->where('sacco_id', $request->sacco);
        }return DataTables::of($points)->filter(function ($query) use ($request) {
            $query->where(function($q) use($request){
                $q->where('name', 'LIKE', '%'.$request->search.'%')
                ->orWhere('phone', 'LIKE', '%'.$request->search.'%');
            });
        })->editColumn('created_at', function ($row) {
            return Carbon::parse($row->end_date)->diffForHumans();
        })
            /*->editColumn('start_date', function ($row) {
                return Carbon::parse($row->start_date)->format('d M, Y');
            })->addColumn('action', function ($row) {
            $actionBtn = '<div style="white-space: nowrap;" class="text-end">' .
                '<span class="d-none id">' . $row->id . '</span>' .
                '<span class="d-none sacco_id">' . $row->sacco_id . '</span>' .
                '<span class="d-none sacco">' . ($row->sacco != null ? $row->sacco->name : "") . '</span>' .
                '<span class="d-none amount">' . $row->amount . '</span>' .
                '<span class="d-none items">' . $row->items . '</span>' .
                '<span class="d-none points_on">' . $row->points_on . '</span>' .
                '<span class="d-none points_type">' . $row->points_type . '</span>' .
                '<span class="d-none role_id">' . $row->role_id . '</span>' .
                '<span class="d-none role">' . ($row->role_id != null ? $row->role->name : "") . '</span>' .
                '<span class="d-none status">' . $row->status . '</span>';
            if (auth()->user()->can('Edit Payment Settings'))
                $actionBtn .= '<button class="btn-edit btn btn-primary btn-sm" data-toggle="modal" data-target="#userModal"><i class="fas fa-edit"></i> Edit</button> ';
            $actionBtn .= '<!--<a href="' . url('/saccos/view/' . $row->id) . '" class="delete btn btn-outline-primary btn-sm"><i class="fas fa-eye"></i> View</a>' . '--></div>';
            return $actionBtn;
        })*/   /* ->addIndexColumn()->escapeColumns([])->make();
    }*/
}
