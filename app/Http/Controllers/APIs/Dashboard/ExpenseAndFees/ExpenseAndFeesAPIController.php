<?php

namespace App\Http\Controllers\APIs\Dashboard\ExpenseAndFees;

use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Concerns\ScopesToOwnedVehicles;
use App\Http\Controllers\Controller;
use App\Models\Summary;
use App\Models\Vehicle;
use App\Models\VehicleExpenseAndFee;
use App\Models\VehicleUser;
use App\Services\Sql\LikeSql;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ExpenseAndFeesAPIController extends Controller
{
    use PaginatesResults;
    use ScopesToOwnedVehicles;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request)
    {
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $from_date = $request->date != "" ? Carbon::parse($request->date) : Carbon::today();
        $to_date = $from_date->copy()->addDays(1);

        $vehicleExpenseFees = VehicleExpenseAndFee::with('vehicle.sacco', 'vehicle.seat','expense_fee')
            ->whereBetween('trans_date', [$from_date, $to_date]);

        // The OWNERSHIP boundary: an investor reads their own buses' expenses,
        // not the SACCO's. Applied before the crew filter below and never
        // gated — an empty array compiles to `0 = 1`, so an investor with no
        // open assignment sees nothing.
        $ownedVehicleIds = $this->ownedVehicleIds();
        if ($ownedVehicleIds !== null) {
            $vehicleExpenseFees->whereIn('vehicle_id', $ownedVehicleIds);
        }

        // Narrows a driver/conductor to the vehicles they are actually on. It is
        // NOT the tenant boundary — that is SaccoScope on the model, which every
        // query here now carries. The distinction matters: this filter is skipped
        // for anyone with no vehicle assignments, which is every office admin, and
        // while it was the only constraint that meant an admin saw the whole
        // platform's expenses rather than their own SACCO's.
        //
        // LEFT AS IT IS, deliberately. The `count() > 0` guard is the fail-open
        // shape the investor filter above exists to avoid, but here the
        // fall-through is load-bearing: an office admin holds no vehicle_users
        // row, and closing it would blank their expenses screen. Tightening it
        // the other way — adding `end_date IS NULL` to match the house
        // definition of an OPEN assignment — would WIDEN any legacy crew account
        // whose only row is already closed, from one bus to the whole SACCO.
        // Investors no longer depend on it either way: their filter ANDs with
        // this one, so whatever this block decides, an investor with nothing
        // assigned still matches nothing.
        $vehicles = VehicleUser::where('user_id', auth()->user()->id)
        ->where('status', true)->pluck('vehicle_id');
        if(count($vehicles)>0){
            $vehicleExpenseFees = $vehicleExpenseFees->whereIn('vehicle_id', $vehicles);
        }
        if ($request->sacco > 0) {
            $vehicleExpenseFees = $vehicleExpenseFees->whereHas('vehicle', function ($query) use ($request) {
                $query->where('sacco_id', $request->sacco);
            });
        }
        if ($request->expense_fee > 0) {
            $vehicleExpenseFees = $vehicleExpenseFees->where('expense_fee_id', $request->expense_fee);
        }
        if ($request->status != "") {
            $vehicleExpenseFees = $vehicleExpenseFees->where('status', $request->status);
        }
        // The whereHas exists ONLY to search, so the whole thing is conditional —
        // otherwise every listing pays for an EXISTS into vehicles to match '%%'.
        $vehicleExpenseFees = $vehicleExpenseFees->when(filled($request->search), fn ($builder) => $builder
            ->whereHas('vehicle', function ($query) use ($request) {
                $query->where('plate', LikeSql::op(), '%' . $request->search . '%');
            }));
        $__meta = $this->pageMeta($vehicleExpenseFees, $request, 20);
        $vehicleExpenseFees = $vehicleExpenseFees->skip($offset)->take(20)->get();
        return response()->json(array_merge(['vehicle_expense_and_fees' => $vehicleExpenseFees], $__meta));
    }
    public function addVehicleExpenseAndFees(Request $request)
    {
        if (auth()->user()->can('Add Expense And Fees') || auth()->user()->can('Edit Expense And Fees')) {
            $validator = Validator::make($request->all(), [
                'id' => 'required|integer|min:0',
                'amount' => 'required|numeric|min:1',
                'vehicle' => 'required|integer|exists:vehicles,id',
                'trans_date' => 'required|date',
                'expense_fee' => 'required|integer|exists:expense_fees,id',
                'status' => 'required|integer|min:0|max:1'
            ]);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }
            // `exists:vehicles,id` proves the vehicle EXISTS, never that it is
            // ours. Re-read it through the scoped model so a posted id belonging
            // to another SACCO resolves to nothing — otherwise this endpoint
            // plants an expense row, and a summary row, inside their books.
            $vehicle = Vehicle::find((int) $request->vehicle);
            if ($vehicle === null) {
                return response()->json(['error' => 'That vehicle is not in your SACCO.'], 404);
            }

            $vehicleExpenseFee = new VehicleExpenseAndFee;
            if ($request->id > 0) {
                // Scoped by the model's own SaccoScope now, so an id from another
                // SACCO is a 404 rather than someone else's row to overwrite.
                $vehicleExpenseFee = VehicleExpenseAndFee::findOrFail($request->id);
            }
            $vehicleExpenseFee->vehicle_id = $vehicle->id;
            $vehicleExpenseFee->expense_fee_id = $request->expense_fee;
            $vehicleExpenseFee->amount = $request->amount;
            $vehicleExpenseFee->trans_date = Carbon::parse($request->trans_date);
            $vehicleExpenseFee->status = $request->status;

            if ($vehicleExpenseFee->save()) {
                // Keyed on the RESOLVED vehicle. This once read $request->vehicle_id,
                // a field that does not exist here — the validated name is
                // `vehicle` — so the lookup got null, never matched, and the branch
                // below minted a DUPLICATE summary for the same (vehicle, date) on
                // every expense entry.
                $summary = Summary::where('trans_date', Carbon::parse($request->trans_date)->format('Y-m-d'))
                    ->where('vehicle_id', $vehicle->id)->first();
                if ($summary == null) {
                    $summary = new Summary;
                    $summary->mpesa_amount = 0;
                    $summary->cash_amount = 0;
                    $summary->mpesa_txn = 0;
                    $summary->cash_txn = 0;
                    $summary->vehicle_id = $vehicle->id;
                    $summary->trans_date = Carbon::parse($request->trans_date)->format('Y-m-d');
                }
                $summary->expense_fee_amount = VehicleExpenseAndFee::where('vehicle_id', $vehicle->id)
                    ->where('trans_date', Carbon::parse($request->trans_date)->format('Y-m-d'))->sum('amount');
                $summary->save();
                return response()->json(['success' => 'Vehicle Expense & Fee updated successfully!']);
            } else {
                return response()->json(['error' => 'Unable to update Vehicle Expense & Fee'], 401);
            }
        } else {
            return response()->json(['error' => 'You do not have permissions to Add/Edit Vehicle Expense And Fees'], 401);
        }
    }
}
