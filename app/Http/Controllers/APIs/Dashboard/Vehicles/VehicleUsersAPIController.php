<?php

namespace App\Http\Controllers\APIs\Dashboard\Vehicles;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class VehicleUsersAPIController extends Controller
{
    public function __construct(){
        $this->middleware('auth:sanctum');
    }

    public function getVehicleUsers(Request $request){
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $vehicleUsers = VehicleUser::with(['user.roles', 'vehicle.seat','sacco']);
        if($request->sacco > 0){
            $vehicleUsers = $vehicleUsers->where('sacco_id', $request->sacco);
        }
        // Only filter when a term was actually typed. An empty box turns this
        // into LIKE '%%' on every column in the group, OR'd with any whereHas
        // below — none of it indexable. The guard wraps the WHOLE group on
        // purpose: guarding one column leaves the orWhere siblings matching
        // unconditionally, which is worse than no guard.
        if (filled($request->search)) {
            $vehicleUsers = $vehicleUsers->where(function($q) use($request){
                $q->whereHas('vehicle',function($query) use($request){
                    $query->where('plate', 'LIKE', '%'.$request->search.'%')
                    ->orWhere('till_number', 'LIKE', '%'.$request->search.'%')
                    ->orWhere('merchant_short_code', 'LIKE', '%'.$request->search.'%');
                })->orWhereHas('user',function($query) use($request){
                    $query->where('firstname', 'LIKE', '%'.$request->search.'%')
                    ->orWhere('lastname', 'LIKE', '%'.$request->search.'%')
                    ->orWhere('phone', 'LIKE', '%'.$request->search.'%')
                    ->orWhere('email', 'LIKE', '%'.$request->search.'%');
                });
            });
        }
        $vehicleUsers = $vehicleUsers->skip($offset)->take(20)
        ->orderBy('created_at', 'DESC')->get();
        return response()->json(['vehicle_users'=>$vehicleUsers]);
    }


    /**
     * Assign a crew member to a vehicle.
     *
     * Both the user and the vehicle are re-read through their scoped models, so
     * an admin cannot attach their driver to another SACCO's bus by posting its
     * id: an out-of-scope id simply does not resolve.
     *
     * A vehicle carries one OPEN assignment at a time. Re-assigning closes the
     * previous one rather than leaving two crews attached to the same bus,
     * which would make "who was driving" unanswerable after the fact.
     */
    public function addVehicleUser(Request $request)
    {
        if (! auth()->user()->can('Add Vehicle Users') && ! auth()->user()->can('Edit Vehicle Users')) {
            return response()->json(['errors' => 'You do not have permissions to assign crew!'], 401);
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|min:1',
            'vehicle_id' => 'required|integer|min:1',
            'start_date' => 'nullable|date',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $vehicle = Vehicle::find($request->vehicle_id);
        $user = User::find($request->user_id);
        if ($vehicle === null || $user === null) {
            return response()->json(['error' => 'Vehicle or user not found in your SACCO'], 404);
        }

        $existing = VehicleUser::where('vehicle_id', $vehicle->id)->where('status', true)->get();
        foreach ($existing as $open) {
            if ((int) $open->user_id === (int) $user->id) {
                return response()->json(['error' => 'That crew member is already assigned to this vehicle'], 409);
            }
            $open->update(['status' => false, 'end_date' => now()]);
        }

        $assignment = VehicleUser::create([
            'user_id' => $user->id,
            'vehicle_id' => $vehicle->id,
            'sacco_id' => $vehicle->sacco_id,
            'start_date' => $request->start_date ?: now(),
            'status' => true,
        ]);

        return response()->json([
            'success' => 'Crew assigned successfully!',
            'vehicle_user' => $assignment->load(['user', 'vehicle']),
        ], 201);
    }

    /**
     * End an assignment. The row is closed (status false + end_date) rather
     * than deleted, because it is the record of who crewed a bus on a day when
     * money was collected.
     */
    public function endVehicleUser(Request $request, int $id)
    {
        if (! auth()->user()->can('Edit Vehicle Users') && ! auth()->user()->can('Delete Vehicle Users')) {
            return response()->json(['errors' => 'You do not have permissions to unassign crew!'], 401);
        }

        $assignment = VehicleUser::find($id);
        if ($assignment === null) {
            return response()->json(['error' => 'Assignment not found'], 404);
        }
        if (! $assignment->status) {
            return response()->json(['error' => 'That assignment is already closed'], 409);
        }

        $assignment->update(['status' => false, 'end_date' => now()]);

        return response()->json(['success' => 'Crew unassigned successfully!', 'vehicle_user' => $assignment->fresh()]);
    }
}
