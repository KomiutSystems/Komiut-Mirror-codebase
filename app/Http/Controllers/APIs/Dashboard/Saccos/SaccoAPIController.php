<?php

namespace App\Http\Controllers\APIs\Dashboard\Saccos;

use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Models\Sacco;
use App\Services\Sql\LikeSql;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SaccoAPIController extends Controller
{
    use PaginatesResults;

    public function __construct(){
        $this->middleware('auth:sanctum');
    }

    public function getSaccos(Request $request){
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $saccos = Sacco::when(filled($request->search), fn ($q) => $q->where('name', LikeSql::op(), '%'.$request->search.'%'));
        if($request->sacco){
            $saccos = $saccos->where('id', $request->sacco);
        }
        $__meta = $this->pageMeta($saccos, $request, 20);
        $saccos = $saccos->skip($offset)->take(20)
        ->orderBy('name', 'ASC')->get();
        return response()->json(array_merge(['saccos'=>$saccos], $__meta));
    }
    public function addSacco(Request $request)
    {
        if(auth()->user()->can('Add Saccos') || auth()->user()->can('Edit Saccos')){
            $validator = Validator::make($request->all(), [
                'id' => 'required|integer|min:0',
                'name' => 'required|string|unique:saccos,name,' . $request->id,
                'slogan' => 'required|string',
                'phone' => 'required|string',
                'status' => 'required|string'
            ]);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }
            $sacco = new Sacco;
            if ($request->id > 0) {
                $sacco = Sacco::findOrFail($request->id);
            } else {
                // An admin typing in a name is not the SACCO claiming itself, so
                // claim_status stays at its 'directory' default — only `source`
                // records who put the row there. Set on creation only: an edit
                // must not rewrite where the name originally came from.
                $sacco->source = 'manual';
            }
            $sacco->name = $request->name;
            $sacco->slogan = $request->slogan;
            $sacco->phone = $request->phone;
            $sacco->status = $request->status;

            if ($sacco->save()) {
                return response()->json(['success' => 'Sacco created successfully!']);
            } else {
                return response()->json(['error' => 'Unable to create sacco'], 401);
            }
        } else {
            return response()->json(['error' => 'You do not have permissions to Add/Edit Saccos'], 401);
        }

    }
}
