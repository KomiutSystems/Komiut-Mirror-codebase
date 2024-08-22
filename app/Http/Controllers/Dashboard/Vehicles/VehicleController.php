<?php

namespace App\Http\Controllers\Dashboard\Vehicles;

use App\Http\Controllers\Controller;
use App\Models\Sacco;
use App\Models\SaccoVehicle;
use App\Models\Status;
use App\Models\Transaction;
use App\Models\Vehicle;
use App\Models\VehicleUser;
use PDF;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Yajra\DataTables\DataTables;

class VehicleController extends Controller
{
    public function __construct(){
        $this->middleware('auth');
        $this->middleware(['permission:View Vehicles']);
    }
    public function index()
    {
        $sacco = Sacco::find(Auth::user()->sacco_id);
        return view('dashboard.vehicles.vehicles', @compact('sacco'));
    }

    public function create(Request $request)
    {
        if(auth()->user()->can('Add Vehicles') || auth()->user()->can('Edit Vehicles')){
            $validator = Validator::make($request->all(), [
                'id' => 'required|min:0|integer',
                'plate' => 'required|string|unique:vehicles,plate,' . $request->id,
                'fleet_no' => 'string|nullable',
                'till_number' => 'integer|nullable',
                'sacco_id' => 'integer|nullable',
                'seat_id' => 'required|exists:seats,id',
                'merchant_short_code' => 'integer|nullable',
                'status' => 'required|min:0|integer',
            ]);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }
            $vehicle = new Vehicle;
            if ($request->id > 0) {
                $vehicle = Vehicle::findOrFail($request->id);
            }
            $vehicle->plate = $request->plate;
            $vehicle->fleet_no = $request->fleet_no;
            $vehicle->till_number = $request->till_number;
            $vehicle->merchant_short_code = $request->merchant_short_code;
            $vehicle->sacco_id = $request->sacco_id;
            $vehicle->user_id = Auth::user()->id;
            $vehicle->seat_id = $request->seat_id;
            $vehicle->status = $request->status;
            if ($vehicle->save()) {
                if($request->sacco_id > 0){
                    if(SaccoVehicle::where('vehicle_id', $vehicle->id)->where('sacco_id', $request->sacco_id)
                    ->where('end_date', null)->count() == 0){
                        $saccoVehicle = new SaccoVehicle;
                        $saccoVehicle->sacco_id = $request->sacco_id;
                        $saccoVehicle->vehicle_id = $vehicle->id;
                        $saccoVehicle->user_id = Auth::user()->id;
                        $saccoVehicle->start_date = Carbon::now();
                        if($saccoVehicle->save()){
                            SaccoVehicle::where('vehicle_id', $vehicle->id)->where('sacco_id', '<>',$request->sacco_id)
                            ->where('end_date', null)->update(['end_date'=>Carbon::now()]);
                        }
                    }
                }
                return response()->json(['success' => 'Vehicle saved successfully']);
            } else {
                return response()->json(['error' => 'Unable to update vehicle'], 401);
            }
        }else{
            return response()->json(['error' => 'Permissions to Add/Edit Vehicle Denied'], 401);
        }
    }

    public function getVehicles(Request $request)
    {

        $vehicle = Vehicle::with(['sacco', 'user', 'seat']);
        if($request->sacco > 0){
            $vehicle = $vehicle->where('sacco_id', $request->sacco);
        }
        if($request->seat > 0){
            $vehicle = $vehicle->where('seat_id', $request->seat);
        }

        $vehicles = VehicleUser::where('user_id', auth()->user()->id)
        ->where('status', true)->pluck('vehicle_id');
        if(count($vehicles)>0){
            $vehicle = $vehicle->whereIn('id', $vehicles);
        }
        if($request->status != ""){
            $vehicle->where('status', $request->status);
        }
        if($request->search != ""){
            $vehicle = $vehicle->where(function($query) use($request){
                $query->where('plate', 'LIKE', '%'.$request->search.'%')->orWhere('fleet_no', 'LIKE', '%'.$request->search.'%')
                ->orWhere('till_number', 'LIKE', '%'.$request->search.'%')->orWhere('merchant_short_code', 'LIKE', '%'.$request->search.'%');
            });
        }

        return DataTables::of($vehicle)
            ->editColumn('created_at', function ($row) {
                return Carbon::parse($row->created_at)->diffForHumans();
            })->addColumn('action', function ($row) {
                $actionBtn = '<div style="white-space: nowrap;" class="text-end">' .
                    '<span class="d-none id">' . $row->id . '</span>' .
                    '<span class="d-none plate">' . $row->plate . '</span>' .
                    '<span class="d-none fleet_no">' . $row->fleet_no . '</span>' .
                    '<span class="d-none till_number">' . $row->till_number . '</span>' .
                    '<span class="d-none merchant_short_code">' . $row->merchant_short_code . '</span>' .
                    '<span class="d-none sacco_id">' . $row->sacco_id . '</span>' .
                    '<span class="d-none sacco">' . ($row->sacco != null?$row->sacco->name:'') . '</span>' .
                    '<span class="d-none seat_id">' . $row->seat_id . '</span>' .
                    '<span class="d-none seat">' . ($row->seat != null?$row->seat->name:'') . '</span>' .
                    '<span class="d-none status">' . $row->status . '</span>' .
                    '<span class="d-none user_id">' . $row->user_id . '</span>';
                    if(auth()->user()->can('Edit Vehicles'))
                        $actionBtn .= '<button class="btn-edit btn btn-primary btn-sm" data-toggle="modal" data-target="#vehicleModal"><i class="fas fa-edit"></i> Edit</button> ';
                    $actionBtn .= '<a href="'.url("vehicles/view/".$row->id).'" class="delete btn btn-outline-primary btn-sm"><i class="fas fa-eye"></i> View</a>'
                    . '</div>';
                return $actionBtn;
            })->addIndexColumn()->escapeColumns([])->make();
    }


    public function searchVehicles(Request $request)
    {
        $vehicles = Vehicle::where('plate', 'LIKE', '%' . $request->q . '%');
        if(Auth::user()->sacco_id > 0){
            $vehicles = $vehicles->where('sacco_id', Auth::user()->sacco_id);
        }
        $vehicles = $vehicles->skip(0)->take(5)->get();
        return json_encode($vehicles);
    }

    public function viewVehicle(Request $request){
        $sacco = Sacco::find(Auth::user()->sacco_id);
        $vehicle = Vehicle::with('sacco', 'seat.seat_arrangements')->where('id', $request->id);
        if($sacco != null){
            $vehicle = $vehicle->where('sacco_id', $sacco->id);
        }
        $vehicle = $vehicle->first();
        if($vehicle == null){
            return redirect()->to('dashboard/home')->with('error', 'Access denied');
        }
        return view('dashboard.vehicles.vehicle', @compact('vehicle', 'sacco'));
    }

    public function printVehicleQRCode(Request $request){
        $vehicle = Vehicle::with('seat.seat_arrangements')->find($request->id);
        if($vehicle == null){
            return redirect()->to('dashboard/home');
        }

        $pdf = PDF::loadView('dashboard.pdf_exports.vehicle_qrcode', @compact('vehicle'));
        // download PDF file with download method
        return $pdf->stream($vehicle->plate . '_qrcode.pdf');
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

        /*$vehicles = VehicleUser::where('user_id', auth()->user()->id)
        ->where('status', true)->pluck('vehicle_id');
        if(count($vehicles)>0){
            $transactions = $transactions->whereIn('vehicle_id', $vehicles);
        }*/
        $transactions->where('vehicle_id', $request->id)->where(function($q) use($request){
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
            return $row->mpesa != null?\Str::limit($row->mpesa->MSISDN, 12,'...'):$row->cash->phone;
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
}
