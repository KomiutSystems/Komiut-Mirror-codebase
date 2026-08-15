<?php

namespace App\Http\Controllers\APIs\Dashboard\ExpenseAndFees;

use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Models\Summary;
use App\Models\VehicleExpenseAndFee;
use App\Models\VehicleUser;
use App\Services\Sql\LikeSql;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ExpenseAndFeesAPIController extends Controller
{
    use PaginatesResults;

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
            $vehicleExpenseFee = new VehicleExpenseAndFee;
            if ($request->id > 0) {
                $vehicleExpenseFee = VehicleExpenseAndFee::findOrFail($request->id);
            }
            $vehicleExpenseFee->vehicle_id = $request->vehicle;
            $vehicleExpenseFee->expense_fee_id = $request->expense_fee;
            $vehicleExpenseFee->amount = $request->amount;
            $vehicleExpenseFee->trans_date = Carbon::parse($request->trans_date);
            $vehicleExpenseFee->status = $request->status;

            if ($vehicleExpenseFee->save()) {
                // `vehicle`, not `vehicle_id` — that is the validated field name, and
                // every other line here (69, 84, 87) already uses it. Reading the
                // wrong key returned null, so this lookup never matched an existing
                // row and the branch below created a DUPLICATE summary for the same
                // (vehicle, date) on every expense entry.
                $summary = Summary::where('trans_date', Carbon::parse($request->trans_date)->format('Y-m-d'))
                    ->where('vehicle_id', $request->vehicle)->first();
                if ($summary == null) {
                    $summary = new Summary;
                    $summary->mpesa_amount = 0;
                    $summary->cash_amount = 0;
                    $summary->mpesa_txn = 0;
                    $summary->cash_txn = 0;
                    $summary->vehicle_id = $request->vehicle;
                    $summary->trans_date = Carbon::parse($request->trans_date)->format('Y-m-d');
                }
                $summary->expense_fee_amount = VehicleExpenseAndFee::where('vehicle_id', $request->vehicle)
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
