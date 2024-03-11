<?php

namespace App\Http\Controllers\Dashboard\ExpenseAndFees;

use App\Http\Controllers\Controller;
use App\Models\Sacco;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ExpenseAndFeesController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(['permission:View Expense And Fees']);
    }

    public function index(){
        $sacco = Sacco::find(Auth::user()->sacco_id);
        return view("dashboard.expense_and_fees.expense_and_fees", @compact('sacco'));
    }public function getExpenseAndFees(Request $request)
    {

        $expenseFees = ExpenseFee::with('sacco');
        if ($request->sacco > 0) {
            $expenseFees = $expenseFees->where('sacco_id', $request->sacco);
        }
        if ($request->status != "") {
            $expenseFees = $expenseFees->where('status', $request->status);
        }
        $expenseFees = $expenseFees->where('name', 'LIKE', '%'.$request->search.'%');
        return DataTables::of($expenseFees)
        ->editColumn('created_at', function ($row) {
                return Carbon::parse($row->created_at)->diffForHumans();
            })->addColumn('action', function ($row) {
            $actionBtn = '<div style="white-space: nowrap;" class="text-end">' .
                '<span class="d-none id">' . $row->id . '</span>' .
                '<span class="d-none sacco_id">' . $row->sacco_id . '</span>' .
                '<span class="d-none sacco">' . ($row->sacco != null?$row->sacco->name:"") . '</span>' .
                '<span class="d-none name">' . $row->name . '</span>' .
                '<span class="d-none type">' . $row->type . '</span>' .
                '<span class="d-none status">' . $row->status . '</span>';
            if (auth()->user()->can('Edit Payment Settings'))
                $actionBtn .= '<button class="btn-edit btn btn-primary btn-sm" data-toggle="modal" data-target="#userModal"><i class="fas fa-edit"></i> Edit</button> ';
            $actionBtn .= '<!--<a href="' . url('/saccos/view/' . $row->id) . '" class="delete btn btn-outline-primary btn-sm"><i class="fas fa-eye"></i> View</a>' . '--></div>';
            return $actionBtn;
        })->addIndexColumn()->escapeColumns([])->make();
    }
    public function addExpenseAndFees(Request $request)
    {
        if(auth()->user()->can('Add Expense And Fees Settings') || auth()->user()->can('Edit Expense And Fees Settings')){
            $validator = Validator::make($request->all(), [
                'id' => 'required|integer|min:0',
                'name' => 'required|string|unique:expense_fees,name,' . $request->id,
                'sacco' => 'nullable|integer|min:1',
                'type' => 'required|string',
                'status' => 'required|integer|min:0|max:1'
            ]);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }
            $expenseFee = new ExpenseFee;
            if ($request->id > 0) {
                $expenseFee = ExpenseFee::findOrFail($request->id);
            }
            $expenseFee->name = $request->name;
            $expenseFee->sacco_id = $request->sacco;
            $expenseFee->type = $request->type;
            $expenseFee->status = $request->status;

            if ($expenseFee->save()) {
                return response()->json(['success' => 'Expense & Fee updated successfully!']);
            } else {
                return response()->json(['error' => 'Unable to update Expense & Fee'], 401);
            }
        } else {
            return response()->json(['error' => 'You do not have permissions to Add/Edit Expense And Fees'], 401);
        }

    }
}
