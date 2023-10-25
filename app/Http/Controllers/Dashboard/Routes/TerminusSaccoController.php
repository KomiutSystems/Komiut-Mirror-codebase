<?php

namespace App\Http\Controllers\Dashboard\Routes;

use App\Http\Controllers\Controller;
use App\Models\SaccoTerminus;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Yajra\DataTables\DataTables;

class TerminusSaccoController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(['permission:View Termini Saccos']);
    }

    public function index(){
        return view('dashboard/routes/termini_saccos');
    }
    public function getTerminiSaccos(Request $request)
    {
        $saccos = SaccoTerminus::with(['sacco', 'user', 'terminus.place']);
        if(strlen($request->sacco) > 0){
            $saccos = $saccos->where('sacco_id',$request->sacco);
        }
        if(strlen($request->terminus) > 0){
            $saccos = $saccos->where('terminus_id',$request->terminus);
        }
        if(strlen($request->place) > 0){
            $saccos = $saccos->whereHas('terminus',function($query) use($request){
                $query->where('place_id', $request->place);
            });
        }
        $saccos = $saccos->orderBy('id', 'DESC');
        return DataTables::of($saccos)
            ->filter(function ($query) use ($request) {
                
            })->editColumn('created_at', function ($row) {
            return Carbon::parse($row->created_at)->diffForHumans();
        })->addColumn('action', function ($row) {
            $actionBtn = '<div style="white-space: nowrap;" class="text-end">' .
                '<span class="d-none id">' . $row->id . '</span>' .
                '<span class="d-none terminus_id">' . $row->terminus_id . '</span>' .
                '<span class="d-none terminus">' . $row->terminus->name . '</span>' .
                '<span class="d-none sacco_id">' . $row->sacco_id . '</span>' .
                '<span class="d-none sacco">' . $row->sacco->name . '</span>' .
                '<span class="d-none status">' . $row->status . '</span>' ;
            if (auth()->user()->can('Edit Queues'))
                $actionBtn .= '<button class="btn-edit btn btn-primary btn-sm" data-toggle="modal" data-target="#routeModal"><i class="fas fa-edit"></i> Edit</button> ';
            /*$actionBtn .= '<a href="' . url('/queues/view/' . $row->id) . '" class="btn btn-outline-primary btn-sm"><i class="fas fa-eye"></i> View</a>'
                . '</div>';*/
            return $actionBtn;
        })->addIndexColumn()->escapeColumns([])->make();
    }
    public function addTerminusSacco(Request $request){
        if(auth()->user()->can('Edit Termini Saccos') || auth()->user()->can('Add Termini Saccos')){
            $validator = Validator::make($request->all(), [
                'id'=>'required|integer|min:0',
                'terminus' => 'required|integer|exists:termini,id',
                'sacco' => 'required|integer|exists:saccos,id',
                'status' => 'required|integer|min:0|max:1',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }
            if(SaccoTerminus::where('terminus_id', $request->terminus)->where('sacco_id', $request->sacco)
            ->where('id', '<>',$request->id)->count() > 0){
                return response()->json(['error'=>'Sacco Terminus already exists'], 401);
            }
            $terminus = new SaccoTerminus();
            if($request->id > 0){
                $terminus = SaccoTerminus::findOrFail($request->id);
            }
            $terminus->terminus_id = $request->terminus;
            $terminus->sacco_id = $request->sacco;
            $terminus->user_id = Auth::user()->id;
            $terminus->status = $request->status;
            if($terminus->save()){
                return response()->json(['success'=>"Sacco Terminus updated successfully!"]);
            }else{
                return response()->json(['error'=>'Unable to update sacco terminus'], 401);
            }
        }else{
            return response()->json(['error'=>'You do not have permissions for this action'], 401);
        }
    }
    
}
