<?php

namespace App\Http\Controllers\Dashboard\Sacco;
use Illuminate\Support\Str;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Models\Sacco;
use App\Models\SaccoVehicle;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Yajra\DataTables\DataTables;

class SaccoVehiclesController extends Controller
{
    public function __construct(){
        $this->middleware('auth');
        $this->middleware(['permission:View Sacco Vehicles']);
    }

    public function index(){
        $sacco = Sacco::find(Auth::user()->sacco_id);
        return view('dashboard.saccos.vehicles', @compact('sacco'));
    }
    public function getVehicles(Request $request)
    {
        $host = $request->getHost();



        return DataTables::of(
            SaccoVehicle::with(['user', 'vehicle', 'sacco'])
                ->when(Str::contains($host, '2safiri.co.ke'), function ($query) {
                    $query->whereHas('vehicle', function ($q) {
                        $q->where('financier', 'coop-bank');
                    });
                })
        )
        ->filter(function($query) use ($request){
            $query->where(function($q) use($request){
                $q->whereHas('vehicle', function($qu) use ($request){
                    $qu->where('plate', 'LIKE', '%'.$request->search.'%')->orWhere('till_number', 'LIKE', '%'.$request->search.'%')
                    ->orWhere('merchant_short_code', 'LIKE', '%'.$request->search.'%');
                });
            })->where('status', $request->status);
            if($request->sacco > 0){
                $query->where('sacco_id', $request->sacco);
            }
        })->editColumn('created_at', function ($row) {
            return Carbon::parse($row->created_at)->diffForHumans();
        })
        ->editColumn('start_date', function ($row) {
            return Carbon::parse($row->start_date)->diffForHumans();
        })
        ->editColumn('end_date', function ($row) {
            return $row->end_date != null?Carbon::parse($row->created_at)->diffForHumans():"";
        })->addColumn('action', function ($row) {
            $actionBtn = '<div style="white-space: nowrap;" class="text-end">' .
                '<span class="d-none id">' . $row->id . '</span>' .
                '<span class="d-none sacco">' . $row->sacco->name . '</span>' .
                '<span class="d-none sacco_id">' . $row->sacco_id . '</span>' .
                '<span class="d-none vehicle_id">' . $row->vehicle->id . '</span>' .
                '<span class="d-none vehicle">' . $row->vehicle->plate.' ('.$row->vehicle->till_number.'|'.$row->vehicle->merchant_short_code.')' . '</span>' .
                '<span class="d-none status">' . $row->status . '</span>';
                if(auth()->user()->can("Edit Sacco Vehicles"))
                    $actionBtn .= '<button class="btn-edit btn btn-primary btn-sm" data-toggle="modal" data-target="#saccoModal"><i class="fas fa-edit"></i> Edit</button> ';
                $actionBtn .= '<!--<a href="' . url('/saccos/view/' . $row->id) . '" class="delete btn btn-outline-primary btn-sm"><i class="fas fa-eye"></i> View</a>' . '--></div>';
            return $actionBtn;
        })->addIndexColumn()->escapeColumns([])->make();
    }
    public function addVehicle(Request $request)
    {
        if(auth()->user()->can('Edit Sacco Vehicles') || auth()->user()->can('Add Sacco Vehicles')){
            $validator = Validator::make($request->all(), [
                'id' => 'required|integer|min:0',
                'sacco' => 'required|exists:saccos,id',
                'vehicle' => 'required|exists:vehicles,id',
                'status' => 'required|integer|min:0|max:1'
            ]);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }

            $saccoVehicle = SaccoVehicle::where('vehicle_id', $request->vehicle)->where('sacco_id', $request->sacco)
            ->where('end_date', null)->first();
            if($saccoVehicle == null){
                SaccoVehicle::where('user_id', $request->member)->where('id', '<>', $request->id)
                ->update(['end_date'=>Carbon::now()]);

                $saccoVehicle = new SaccoVehicle;
                if ($request->id > 0) {
                    $saccoVehicle = SaccoVehicle::findOrFail($request->id);
                }else{
                    $saccoVehicle->start_date = Carbon::now();
                }
            }
            $saccoVehicle->sacco_id = $request->sacco;
            $saccoVehicle->vehicle_id = $request->vehicle;
            $saccoVehicle->status = $request->status;
            $saccoVehicle->user_id = Auth::user()->id;

            if ($saccoVehicle->save()) {
                SaccoVehicle::where('user_id', $request->member)->where('id', '<>',$saccoVehicle->id)
                ->where('end_date', null)->update(['end_date'=>Carbon::now(), 'status'=>0]);
                Vehicle::where('id', $request->vehicle)->update(['sacco_id'=>$request->sacco]);
                return response()->json(['success' => 'Vehicle updated successfully!']);
            } else {
                return response()->json(['error' => 'Unable to update Vehicle'], 401);
            }
        }else {
            return response()->json(['error' => 'Permissions to Add/Edit Sacco Vehicle Denied'], 401);
        }

    }
    /*
    public function addSaccoVehicle(Request $request): JsonResponse|RedirectResponse
    {

        $validator = $this->validateSaccoVehicle($request);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $saccoVehicleExists = SaccoVehicles::where('vehicle_id', '=', $request->input('vehicle'))->exists();
        if ($saccoVehicleExists) {
            $saccoVehicle = SaccoVehicles::where('vehicle_id', '=', $request->input('vehicle'))->first();
        } else {
            $saccoVehicle = new SaccoVehicles();
        }


        $saccoVehicle->vehicle_id = $request->input('vehicle');
        $saccoVehicle->sacco_id = $request->input('sacco_id');
        $saccoVehicle->user_id = Auth::user()->id;
        $saccoVehicle->date_left = $request->input('date_left');
        $saccoVehicle->status = $request->input('status') ? $request->input('status') : 1;


        if ($saccoVehicle->save()) {
            return redirect()->back()->with('success', 'Dashboard vehicle updated successfully');
        } else {
            return redirect()->back()->with('error', 'Failed to update Dashboard vehicle');
        }


    }

    private function validateSaccoVehicle(Request $request): \Illuminate\Validation\Validator
    {
        return Validator::make(
            $request->all(),
            [
                "sacco_id" => "required|integer|min:1",
                "vehicle" => "required|integer|min:1",
            ]
        );
    }

    public function getSaccoVehicles($id): JsonResponse
    {

        $vehicles = SaccoVehicles::with([
            'vehicle_id',
            'user_id',
            'sacco_id' => function ($query) use ($id) {
                $query->where('id','=', $id);
            },
        ]);

        return DataTables::of($vehicles)
            ->addColumn('action', function ($row) {
                $actionBtn = '<div style="white-space: nowrap;" class="text-end">' .
                    '<span class="d-none id">' . $row->id . '</span>' .
                    '<span class="d-none plate">' . $row->plate . '</span>' .
                    '<span class="d-none fleet_no">' . $row->fleet_no . '</span>' .
                    '<span class="d-none till_number">' . $row->till_number . '</span>' .
                    '<span class="d-none merchant_short_code">' . $row->merchant_short_code . '</span>' .
                    '<span class="d-none sacco_id">' . $row->sacco_id . '</span>' .
                    '<span class="d-none status">' . $row->status . '</span>' .
                    '<span class="d-none user_id">' . $row->user_id . '</span>' .
                    '<button class="btn-edit btn btn-danger btn-sm" data-toggle="modal" data-target="#vehicleModal"> Remove </button> '

                    . '</div>';
                return $actionBtn;
            })->addIndexColumn()->escapeColumns([])->make();
    }

    public function removeSaccoVehicle($id){}*/

}
