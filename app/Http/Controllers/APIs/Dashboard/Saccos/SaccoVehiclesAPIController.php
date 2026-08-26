<?php

namespace App\Http\Controllers\APIs\Dashboard\Saccos;

use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\SaccoVehicle;
use App\Models\Vehicle;
use App\Services\Sql\LikeSql;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class SaccoVehiclesAPIController extends Controller
{
    use PaginatesResults, ResolvesTenant;


    public function __construct(){
        $this->middleware('auth:sanctum');
    }

    public function getSaccoVehicles(Request $request){
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $saccoVehicles = SaccoVehicle::with(['user', 'vehicle.seat','vehicle.sacco','sacco']);
        if($request->sacco > 0){
            $saccoVehicles = $saccoVehicles->where('sacco_id', $request->sacco);
        }
        // Only filter when a term was actually typed. An empty box turns this
        // into LIKE '%%' on every column in the group, OR'd with any whereHas
        // below — none of it indexable. The guard wraps the WHOLE group on
        // purpose: guarding one column leaves the orWhere siblings matching
        // unconditionally, which is worse than no guard.
        if (filled($request->search)) {
            $saccoVehicles = $saccoVehicles->whereHas('vehicle',function($query) use($request){
                $query->where('plate', LikeSql::op(), '%'.$request->search.'%')
                ->orWhere('till_number', LikeSql::op(), '%'.$request->search.'%')
                ->orWhere('merchant_short_code', LikeSql::op(), '%'.$request->search.'%');
            });
        }
        $__meta = $this->pageMeta($saccoVehicles, $request, 20);
        $saccoVehicles = $saccoVehicles->skip($offset)->take(20)
        ->orderBy('created_at', 'DESC')->get();
        return response()->json(array_merge(['sacco_vehicles'=>$saccoVehicles], $__meta));
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

            // `exists:saccos,id` and `exists:vehicles,id` prove these rows exist,
            // never that they are ours. This method ends in
            // Vehicle::update(['sacco_id' => ...]), i.e. it MOVES a bus between
            // fleets — so an unchecked pair here either pushes our vehicle into
            // someone else's SACCO or plants a membership row in it.
            $saccoId = $this->resolveSaccoId($request, 'sacco');
            if ($saccoId === null) {
                return $this->foreignSaccoDenied();
            }

            // Re-read through the scoped model so a vehicle outside our SACCO is
            // simply not found.
            $vehicle = Vehicle::find((int) $request->vehicle);
            if ($vehicle === null) {
                return response()->json(['error' => 'That vehicle is not in your SACCO.'], 404);
            }

            $saccoVehicle = SaccoVehicle::where('vehicle_id', $vehicle->id)->where('sacco_id', $saccoId)
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
            $saccoVehicle->sacco_id = $saccoId;
            $saccoVehicle->vehicle_id = $vehicle->id;
            $saccoVehicle->status = $request->status;
            $saccoVehicle->user_id = Auth::user()->id;

            if ($saccoVehicle->save()) {
                SaccoVehicle::where('user_id', $request->member)->where('id', '<>',$saccoVehicle->id)
                ->where('end_date', null)->update(['end_date'=>Carbon::now(), 'status'=>0]);
                Vehicle::where('id', $vehicle->id)->update(['sacco_id'=>$saccoId]);
                return response()->json(['success' => 'Vehicle updated successfully!']);
            } else {
                return response()->json(['error' => 'Unable to update Vehicle'], 401);
            }
        }else {
            return response()->json(['error' => 'Permissions to Add/Edit Sacco Vehicle Denied'], 401);
        }

    }

}
