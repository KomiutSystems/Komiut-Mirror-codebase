<?php

namespace App\Http\Controllers\APIs\Dashboard\Vehicles;

use App\Enums\Financier;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Resources\VehicleResource;
use App\Models\Sacco;
use App\Models\SaccoVehicle;
use App\Models\Seat;
use App\Models\Vehicle;
use App\Services\Sql\LikeSql;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class VehiclesAPIController extends Controller
{
    use PaginatesResults;

    public function __construct(){
        $this->middleware('auth:sanctum');
    }

    public function getVehicles(Request $request){
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $vehicles = Vehicle::with(['user', 'seat','sacco']);

        // The bank boundary is NOT applied here: Vehicle carries
        // BelongsToFinancier, so the global scope has already constrained this
        // query to the fleet a bank user financed. Applying it again by hand
        // would only repeat the same predicate.
        //
        // Keeping it on the model rather than in this controller is the whole
        // point — a per-controller boundary is how Cash, Mpesa and
        // QrcodePayment came to be unscoped in the first place, because the
        // next endpoint someone writes inherits a model's scope for free and
        // inherits nothing from a controller.

        $veh = explode(',', str_replace(']', '', str_replace('[', '', $request->vehicles)));
        $all_vehicles = [];

        foreach ($veh as $vehicle) {
            $v = trim($vehicle);
            if($v != ""){
                array_push($all_vehicles, trim($vehicle));
            }
        }

        if($request->sacco > 0){
            $vehicles = $vehicles->where('sacco_id', $request->sacco);
        }

        if($request->seat > 0){
            $vehicles = $vehicles->where('seat_id', $request->seat);
        }
        if(count($all_vehicles)>0){
            $vehicles = $vehicles->whereIn('id', $all_vehicles);
        }
        // Only filter when a term was actually typed. An empty box turns this
        // into LIKE '%%' on every column in the group, OR'd with any whereHas
        // below — none of it indexable. The guard wraps the WHOLE group on
        // purpose: guarding one column leaves the orWhere siblings matching
        // unconditionally, which is worse than no guard.
        if (filled($request->search)) {
            $vehicles = $vehicles->where(function($query) use($request){
                $query->where('plate', LikeSql::op(), '%'.$request->search.'%')
                ->orWhere('till_number', LikeSql::op(), '%'.$request->search.'%')
                ->orWhere('merchant_short_code', LikeSql::op(), '%'.$request->search.'%');
            });
        }
        $__meta = $this->pageMeta($vehicles, $request, 20);
        $vehicles = $vehicles->skip($offset)->take(20)
        ->orderBy('created_at', 'DESC')->get();
        // Resource-backed response: same {"vehicles":[...]} envelope (wrapping is
        // disabled globally), but the field shape is now an explicit contract.
        return response()->json(array_merge(['vehicles' => VehicleResource::collection($vehicles)], $__meta));
    }

    public function addVehicle(Request $request)
    {
        if(auth()->user()->can('Add Vehicles') || auth()->user()->can('Edit Vehicles')){
            // Blank and absent must mean the same thing before anything reads
            // this field: an edit form that posts an empty box is not asking
            // for a financier, and '' would otherwise fail the allow-list below
            // and 400 an edit that never touched the field. The is_string guard
            // is for the cast — financier[]=x would raise an "Array to string
            // conversion" here; a non-string is left for the rule to reject.
            if (is_string($request->input('financier')) && trim($request->input('financier')) === '') {
                $request->merge(['financier' => null]);
            }

            $validator = Validator::make($request->all(), [
                'id' => 'required|min:0|integer',
                'plate' => 'required|string|unique:vehicles,plate,' . $request->id,
                'fleet_no' => 'string|nullable',
                'till_number' => 'integer|nullable',
                'sacco' => 'string|nullable',
                'seat' => 'required|exists:seats,name',
                'merchant_short_code' => 'integer|nullable',
                // The BANK's collection account, distinct from the Safaricom
                // till above. Strings, not integers: bank account numbers can
                // carry leading zeros, which an integer cast silently eats.
                'ncba_till' => 'string|nullable|max:30',
                'coop_till' => 'string|nullable|max:30',
                // An allow-list, not free text. This column decides which bank
                // is shown the vehicle and its money, so 'string|max:60' let a
                // typo ("NCBA " with a space, "ncba") quietly remove a bus from
                // the bank that financed it — with nothing to see in the UI.
                'financier' => ['nullable', Rule::enum(Financier::class)],
                'status' => 'required|min:0|integer',
            ]);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }
            $vehicle = new Vehicle;
            if ($request->id > 0) {
                $vehicle = Vehicle::findOrFail($request->id);
            }
            $sacco = Sacco::where('name', $request->sacco)->first();
            if($sacco != null){
                $vehicle->sacco_id = $sacco->id;
            }
            $seat = Seat::where('name', $request->seat)->first();
            if($seat != null){
                $vehicle->seat_id = $seat->id;
            }
            $vehicle->plate = $request->plate;
            $vehicle->fleet_no = $request->fleet_no;
            $vehicle->till_number = $request->till_number;
            $vehicle->merchant_short_code = $request->merchant_short_code;
            // Only overwrite when supplied: an edit that does not mention a
            // bank till must not wipe one that was already issued.
            foreach (['ncba_till', 'coop_till'] as $field) {
                if ($request->exists($field)) {
                    $vehicle->{$field} = $request->input($field);
                }
            }

            // `financier` is off the tenant-writable surface. It is not a
            // property of the vehicle the SACCO owns, it is the key deciding
            // which bank audits that vehicle's money — so leaving it in the
            // loop above meant any Fleet Manager could move their bus out from
            // under NCBA's view by editing a text field, on an endpoint that
            // doubles as the edit endpoint whenever an `id` is supplied.
            //
            // A superadmin's submission is authoritative. Anyone else is refused
            // only when EDITING an existing vehicle and only on an actual
            // CHANGE, both sides resolved through the enum first, so an edit
            // form that faithfully round-trips the stored value stays a no-op
            // and still saves — otherwise every SACCO edit of an unrelated
            // field would start failing. On CREATE there is no stored value to
            // defend, so the field is dropped rather than refused (see below):
            // refusing there would 403 the request and create no vehicle at all.
            if ($request->exists('financier')) {
                $submitted = Financier::tryParse($request->input('financier'));

                if (auth()->user()->isSuperAdmin()) {
                    $vehicle->financier = $submitted?->value;
                } elseif (! $vehicle->exists) {
                    // CREATE. There is no stored value to defend, so a submitted
                    // financier is simply not this caller's to set and is dropped
                    // rather than refused. Refusing would 403 the whole request
                    // and create no vehicle at all, which breaks ordinary vehicle
                    // creation for every SACCO whose form posts the field at all
                    // — a bus that cannot be added is a worse outcome than a bus
                    // added without a bank, and a superadmin assigns the bank.
                } elseif ($submitted !== Financier::tryParse($vehicle->financier)) {
                    // 403, not the 401 this method returns for its own
                    // permission denial: the caller IS authenticated and may
                    // well hold 'Edit Vehicles'. It is this one field they are
                    // not allowed to move.
                    return response()->json([
                        'error' => 'Only a superadmin can change which bank finances a vehicle',
                    ], 403);
                }
            }
            $vehicle->user_id = Auth::user()->id;
            $vehicle->status = $request->status;
            if ($vehicle->save()) {
                if($sacco != null){
                    if(SaccoVehicle::where('vehicle_id', $vehicle->id)->where('sacco_id', $sacco->id)
                    ->where('end_date', null)->count() == 0){
                        $saccoVehicle = new SaccoVehicle;
                        $saccoVehicle->sacco_id = $sacco->id;
                        $saccoVehicle->vehicle_id = $vehicle->id;
                        $saccoVehicle->user_id = Auth::user()->id;
                        $saccoVehicle->start_date = Carbon::now();
                        if($saccoVehicle->save()){
                            SaccoVehicle::where('vehicle_id', $vehicle->id)->where('sacco_id', '<>',$sacco->id)
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
}
