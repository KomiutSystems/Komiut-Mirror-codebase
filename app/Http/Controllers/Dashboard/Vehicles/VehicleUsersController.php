<?php

namespace App\Http\Controllers\Dashboard\Vehicles;

use App\Http\Controllers\Controller;
use App\Models\Sacco;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleUser;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Yajra\DataTables\Facades\DataTables;

class VehicleUsersController extends Controller
{
    public function __construct(){
        $this->middleware('auth');
        $this->middleware(['permission:View Vehicle Users']);
    }

    public function index(){
        $sacco = Sacco::find(Auth::user()->sacco_id);
        return view('dashboard.vehicles.vehicle_users', @compact('sacco'));
    }
    public function getVehicleUsers(Request $request)
    {
        return DataTables::of(VehicleUser::with(['user.roles', 'vehicle','sacco']))
        ->filter(function($query) use ($request){
            $query->where(function($q) use($request){
                $q->whereHas('vehicle', function($qu) use ($request){
                    $qu->where('plate', 'LIKE', '%'.$request->search.'%')/*->orWhere('till_number', 'LIKE', '%'.$request->search.'%')
                    ->orWhere('merchant_short_code', 'LIKE', '%'.$request->search.'%')*/;
                })->orWhereHas('user', function($qu) use ($request){
                    $qu->where('firstname', 'LIKE', '%'.$request->search.'%')->orWhere('lastname', 'LIKE', '%'.$request->search.'%')
                    ->orWhere('email', 'LIKE', '%'.$request->search.'%')->orWhere('phone', 'LIKE', '%'.$request->search.'%');
                });
            });
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
        })
        ->editColumn('role', function ($row) {
            return $row->user->roles != null?$row->user->roles[0]->name:"-";
        })->addColumn('action', function ($row) {
            $actionBtn = '<div style="white-space: nowrap;" class="text-end">' .
                '<span class="d-none id">' . $row->id . '</span>' .
                '<span class="d-none user">' . $row->user->firstname .' '.$row->user->lastname.' ('.$row->user->email.' | '.$row->user->phone.')</span>' .
                '<span class="d-none user_id">' . $row->user_id . '</span>' .
                '<span class="d-none vehicle_id">' . $row->vehicle->id . '</span>' .
                '<span class="d-none vehicle">' . $row->vehicle->plate.' ('.$row->vehicle->till_number.'|'.$row->vehicle->merchant_short_code.')' . '</span>' .
                '<span class="d-none status">' . $row->status . '</span>';
                if(auth()->user()->can("Edit Vehicle Users"))
                    $actionBtn .= '<button class="btn-edit btn btn-primary btn-sm" data-toggle="modal" data-target="#vehicleModal"><i class="fas fa-edit"></i> Edit</button> ';
                $actionBtn .= '<!--<a href="' . url('/saccos/view/' . $row->id) . '" class="delete btn btn-outline-primary btn-sm"><i class="fas fa-eye"></i> View</a>' . '--></div>';
            return $actionBtn;
        })->addIndexColumn()->escapeColumns([])->make();
    }
    public function addVehicleUser(Request $request)
    {
        if(auth()->user()->can('Edit Vehicle Users') || auth()->user()->can('Add Vehicle Users')){
            $validator = Validator::make($request->all(), [
                'id' => 'required|integer|min:0',
                'user' => 'required|exists:users,id',
                'vehicle' => 'required|exists:vehicles,id',
                'status' => 'required|integer|min:0|max:1'
            ]);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }
            $user = User::find($request->user);
            if($request->id <= 0){
                $vehicle = Vehicle::find($request->vehicle);
                if($vehicle->sacco_id != $user->sacco_id){
                    return response()->json(['error'=>'User Sacco and Vehicle Sacco Do not match'], 401);
                }
            }
            
            $vehicleUser = VehicleUser::where('vehicle_id', $request->vehicle)->where('user_id', $request->user)
            ->where('end_date', null)->first();
            if($vehicleUser == null){
                /*VehicleUser::where('user_id', $request->member)->where('id', '<>', $request->id)
                ->update(['end_date'=>Carbon::now()]);*/
                
                $vehicleUser = new VehicleUser;
                if ($request->id > 0) {
                    $vehicleUser = VehicleUser::findOrFail($request->id);
                }else{
                    $vehicleUser->start_date = Carbon::now();
                }
            }
            $vehicleUser->user_id = $request->user;
            $vehicleUser->sacco_id = $user->sacco_id;
            $vehicleUser->vehicle_id = $request->vehicle;
            $vehicleUser->status = $request->status;
            //$vehicleUser->user_id = Auth::user()->id;

            if ($vehicleUser->save()) {
                /*SaccoVehicle::where('user_id', $request->member)->where('id', '<>',$vehicleUser->id)
                ->where('end_date', null)->update(['end_date'=>Carbon::now(), 'status'=>0]);
                Vehicle::where('id', $request->vehicle)->update(['sacco_id'=>$request->sacco]);*/
                return response()->json(['success' => 'Vehicle User updated successfully!']);
            } else {
                return response()->json(['error' => 'Unable to update Vehicle User'], 401);
            }
        }else {
            return response()->json(['error' => 'Permissions to Add/Edit Vehicle User Vehicle Denied'], 401);
        }

    }
}
