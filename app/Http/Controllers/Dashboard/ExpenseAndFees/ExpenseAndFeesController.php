<?php

namespace App\Http\Controllers\Dashboard\ExpenseAndFees;

use App\Http\Controllers\Controller;
use App\Models\ExpenseFee;
use App\Models\Sacco;
use App\Models\Summary;
use App\Models\VehicleExpenseAndFee;
use App\Models\VehicleUser;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Yajra\DataTables\DataTables;

class ExpenseAndFeesController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(['permission:View Expense And Fees']);
    }

    public function index()
    {
        $sacco = Sacco::find(Auth::user()->sacco_id);
        return view("dashboard.expense_and_fees.expense_and_fees", @compact('sacco'));
    }
    public function getExpenseAndFees(Request $request)
    {

        $from_date = Carbon::parse($request->from_date);
        $to_date = Carbon::parse($request->to_date);
        $vehicleExpenseFees = VehicleExpenseAndFee::with('vehicle.sacco', 'expense_fee')
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
        $vehicleExpenseFees = $vehicleExpenseFees->whereHas('vehicle', function ($query) use ($request) {
            $query->where('plate', 'LIKE', '%' . $request->search . '%');
        });
        return DataTables::of($vehicleExpenseFees)
            ->editColumn('created_at', function ($row) {
                return Carbon::parse($row->created_at)->diffForHumans();
            })->editColumn('trans_date', function ($row) {
                return Carbon::parse($row->trans_date)->format('d M, Y');
            })->editColumn('amount', function ($row) {
                return number_format($row->amount, 2, '.', ',');
            })->addColumn('action', function ($row) {
                $actionBtn = '<div style="white-space: nowrap;" class="text-end">' .
                    '<span class="d-none id">' . $row->id . '</span>' .
                    '<span class="d-none amount">' . $row->amount . '</span>' .
                    '<span class="d-none vehicle">' . ($row->vehicle != null ? $row->vehicle->plate : "") . '</span>' .
                    '<span class="d-none vehicle_id">' . $row->vehicle_id . '</span>' .
                    '<span class="d-none expense_fee">' . ($row->expense_fee != null ? $row->expense_fee->name : "") . '</span>' .
                    '<span class="d-none expense_fee_id">' . $row->expense_fee_id . '</span>' .
                    '<span class="d-none trans_date">' . Carbon::parse($row->trans_date)->format('Y-m-d') . '</span>' .
                    '<span class="d-none status">' . $row->status . '</span>';
                if (auth()->user()->can('Edit Expense And Fees'))
                    $actionBtn .= '<button class="btn-edit btn btn-primary btn-sm" data-toggle="modal" data-target="#userModal"><i class="fas fa-edit"></i> Edit</button> ';
                $actionBtn .= '<!--<a href="' . url('/saccos/view/' . $row->id) . '" class="delete btn btn-outline-primary btn-sm"><i class="fas fa-eye"></i> View</a>' . '--></div>';
                return $actionBtn;
            })->addIndexColumn()->escapeColumns([])->make();
    }
    public function addExpenseAndFees(Request $request)
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
                $summary = Summary::where('trans_date', Carbon::parse($request->trans_date)->format('Y-m-d'))
                    ->where('vehicle_id', $request->vehicle_id)->first();
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

    public function searchExpenseAndFees(Request $request)
    {
        $expense_and_fees = ExpenseFee::where('name', 'LIKE', '%' . $request->q . '%');
        if (Auth::user()->sacco_id > 0) {
            $expense_and_fees = $expense_and_fees->where('sacco_id', Auth::user()->sacco_id);
        }
        $expense_and_fees = $expense_and_fees->skip(0)->take(5)->get();
        return json_encode($expense_and_fees);
    }
}
