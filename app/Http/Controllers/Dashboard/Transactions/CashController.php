<?php

namespace App\Http\Controllers\Dashboard\Transactions;

use App\Http\Controllers\Controller;
use App\Models\Cash;
use App\Models\Sacco;
use App\Models\VehicleUser;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Yajra\DataTables\DataTables;
use DB;

class CashController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(['permission:View Transactions']);
    }
    public function index(){
        $sacco = Sacco::find(Auth::user()->sacco_id);
        return view('dashboard.transactions.cashes', @compact('sacco'));
    }
    public function getCash(Request $request){
        $from_date = Carbon::parse($request->date);
        $to_date = Carbon::parse($request->date)->addDay();
        /*
        $from_date = Carbon::parse($request->from_date);
        $to_date = Carbon::parse($request->to_date);*/
        $cash = Cash::with(['vehicle.sacco'])
        ->whereBetween('trans_date',[$from_date, $to_date]);
        if($request->sacco > 0){
            $cash = $cash->whereHas('vehicle', function($query) use($request){
                $query->where('sacco_id', $request->sacco);
            });
        }

        $vehicles = VehicleUser::where('user_id', auth()->user()->id)
                ->where('status', true)->pluck('vehicle_id');
                if(count($vehicles)>0){
                    $cash = $cash->whereIn('vehicle_id', $vehicles);
                }
        $cash = $cash->where(function($query)use($request){
            $query->where('trans_id', 'LIKE', $request->search.'%')
            ->orWhere(DB::Raw('CONCAT(firstname, " ", lastname)'), 'LIKE', $request->search.'%')
            ->orWhere('phone', 'LIKE', $request->search.'%')
            ->orWhereHas('vehicle',function($q)use($request){
                $q->where('plate', 'LIKE', $request->search.'%');
            });
        })->skip(0)->take(5000)->orderBy('trans_date', 'DESC');

        return DataTables::of($cash)
        ->editColumn('created_at', function ($row) {
            return Carbon::parse($row->created_at)->diffForHumans();
        })->addColumn("name", function($row){
            return $row->firstname.' '.$row->lastname;
        })->addColumn("trans_date", function($row){
            $date = $row->trans_date;
            return Carbon::parse($date)->format('d M, Y h:i A');
        })->editColumn("phone", function($row){
            return substr($row->phone, 0, 12);
        })->addIndexColumn()->escapeColumns([])->make();
    }
}
