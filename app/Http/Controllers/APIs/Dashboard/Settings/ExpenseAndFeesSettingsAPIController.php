<?php

namespace App\Http\Controllers\APIs\Dashboard\Settings;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Models\ExpenseFee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ExpenseAndFeesSettingsAPIController extends Controller
{
    use PaginatesResults;

    public function __construct(){
        $this->middleware('auth:sanctum');
    }
    public function index(Request $request){

        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;

        $expense_and_fees = ExpenseFee::with("sacco");
        if($request->sacco > 0){
            $expense_and_fees  = $expense_and_fees->where("sacco_id", $request->sacco);
        }

        $__meta = $this->pageMeta($expense_and_fees, $request, 20);
        $expense_and_fees = $expense_and_fees->when(filled($request->search), fn ($q) => $q->where('name', 'LIKE', '%'.$request->search.'%'))
        ->orderBy('name', 'ASC')->skip($offset)->take(20)->get();
        return response()->json(array_merge(['expense_and_fees'=>$expense_and_fees], $__meta));
    }


    public function addExpenseFee(Request $request)
    {
        if (auth()->user()->can('Add Expense And Fees Settings') || auth()->user()->can('Edit Expense And Fees Settings')) {
            $validator = Validator::make($request->all(), [
                'id' => 'required|integer|min:0',
                'name' => 'required|string',
                'sacco' => 'nullable|integer',
                'type'=>'required|string',
                'status' => 'required|integer|max:1,min:0'
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }
            if(ExpenseFee::where('id', '<>', $request->id)->where('name', $request->name)->where('sacco_id', $request->sacco)->count() > 0){
                return response()->json(['error'=>'Expense/Fee already exists!'], 401);
            }
            $expenseFee = new ExpenseFee();
            if ($request->id > 0) {
                $expenseFee = ExpenseFee::findOrFail($request->id);
            }

            $expenseFee->name = $request->name;
            $expenseFee->type= $request->type;
            $expenseFee->sacco_id = $request->sacco;
            $expenseFee->status = $request->status;
            if ($expenseFee->save()) {

                return response()->json(['success' => "Expense And Fee updated successfully!"]);
            } else {
                return response()->json(['error' => 'Unable to update expense/fee'], 401);
            }
        } else {
            return response()->json(['error' => 'You do not have permissione to Add/Edit Expense/Fee'], 401);
        }
    }

}
