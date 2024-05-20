<?php

namespace App\Http\Controllers\Dashboard\QRCode;

use App\Http\Controllers\Controller;
use App\Models\QrcodePayment;
use App\Models\Sacco;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Yajra\DataTables\DataTables;
class QrCodeTransactionsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }
    public function index()
    {
        if(auth()->user()->can('View QRCode Payments')){
            return 'OK';
        }else{
            $permission = Permission::where('name', 'View QRCode Payments')->first();
            $role = Role::where('name', 'Super Admin')->first();
            $role->givePermissionTo($permission);
            //return json_encode($permission);
        }
        if(!auth()->user()->can('View QRCode Payments')){
            return 'Haionekani bado!!';
        }
        $sacco = Sacco::find(Auth::user()->sacco_id);
        return view('dashboard.qrcode.qrcode_payments', @compact('sacco'));
    }public function getQRCodePayments(Request $request){
        $from_date = Carbon::parse($request->from_date);
        $to_date = Carbon::parse($request->to_date);
        $transactions = QrcodePayment::with(['mpesa_qrcode_payment', 'vehicle', 'user'])
        ->whereBetween('created_at',[$from_date, $to_date]);
        if($request->sacco > 0){
            $transactions = $transactions->whereHas('vehicle', function($query) use($request){
                $query->where('sacco_id', $request->sacco);
            });
        }

        if(!auth()->user()->can('View QRCode Payments')){
            $transactions = $transactions->where('user_id', auth()->user()->id);
        }
        $transactions = $transactions->where(function($q) use($request){
            $q->whereHas('user',function($query)use($request){
                $query->where(DB::Raw('CONCAT(firstname, " ", lastname)'), 'LIKE', '%'.$request->search.'%')
                ->orWhere('phone', 'LIKE', '%'.$request->search.'%');
            })->orWhereHas('vehicle',function($query)use($request){
                $query->where('plate', 'LIKE', '%'.$request->search.'%');
            })/*->orWhereHas('vehicle.sacco',function($query)use($request){
                $query->where('name', 'LIKE', '%'.$request->search.'%');
            })*/;
        })->orderBy('created_at', 'DESC');

        return DataTables::of($transactions)
        ->editColumn('created_at', function ($row) {
            return Carbon::parse($row->created_at)->setTimezone('Africa/Nairobi')->format('d M, Y h:i A');
        })->editColumn('status', function ($row) {
            return "<span class='badge ".($row->status?'badge-primary':'badge-secondary')."'>".($row->status?'Paid':'Pending')."</span>";
        })->addColumn("transid", function($row){
            return $row->mpesa != null?$row->mpesa->TransID: $row->cash->trans_id;
        })->addIndexColumn()->escapeColumns([])->make();
    }
}
