<?php

namespace App\Http\Controllers\Dashboard\Sacco;

use App\Http\Controllers\Controller;
use App\Models\Sacco;
use App\Models\SaccoUser;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Yajra\DataTables\DataTables;

class SaccoMembersController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(['permission:View Sacco Members']);
    }
    public function index(){
        $sacco = Sacco::find(Auth::user()->sacco_id);
        return view('dashboard.saccos.members',  @compact('sacco'));
    }
    
    public function getMembers(Request $request)
    {
        return DataTables::of(SaccoUser::with(['user', 'creator','sacco']))
        ->filter(function($query) use ($request){
            $query->where(function($q) use($request){
                $q->whereHas('user', function($qu) use ($request){
                    $qu->where('firstname', 'LIKE', '%'.$request->search.'%')->orWhere('lastname', 'LIKE', '%'.$request->search.'%')
                    ->orWhere('email', 'LIKE', '%'.$request->search.'%')->orWhere('phone', 'LIKE', '%'.$request->search.'%');
                });
            })->where('status', $request->status);
            if($request->sacco > 0){
                $query->where('sacco_id', $request->sacco);
            }
        })->editColumn('created_at', function ($row) {
            return Carbon::parse($row->created_at)->diffForHumans();
        })
        ->editColumn('start_date', function ($row) {
            return Carbon::parse($row->start_date)->diffForHumans();
        })
        ->editColumn('end_date', function ($row) {
            return $row->end_date != null?Carbon::parse($row->created_at)->diffForHumans():"";
        })->addColumn('action', function ($row) {
                $actionBtn = '<div style="white-space: nowrap;" class="text-end">' .
                    '<span class="d-none id">' . $row->id . '</span>' .
                    '<span class="d-none sacco">' . $row->sacco->name . '</span>' .
                    '<span class="d-none sacco_id">' . $row->sacco_id . '</span>' .
                    '<span class="d-none user_id">' . $row->user->id . '</span>' .
                    '<span class="d-none user">' . $row->user->firstname.' '.$row->user->lastname.' ('.$row->user->email.'|'.$row->user->phone.')' . '</span>' .
                    '<span class="d-none status">' . $row->status . '</span>';
                    if(auth()->user()->can('Edit Sacco Members'))
                        $actionBtn .= '<button class="btn-edit btn btn-primary btn-sm" data-toggle="modal" data-target="#saccoModal"><i class="fas fa-edit"></i> Edit</button> ';
                    $actionBtn .= '<!--<a href="' . url('/saccos/view/' . $row->id) . '" class="delete btn btn-outline-primary btn-sm"><i class="fas fa-eye"></i> View</a>' . '--></div>';
                return $actionBtn;
            })->addIndexColumn()->escapeColumns([])->make();
        }
    public function addMember(Request $request)
    {
        if(auth()->user()->can('Edit Sacco Members') || auth()->user()->can('Add Sacco Members')){
            $validator = Validator::make($request->all(), [
                'id' => 'required|integer|min:0',
                'sacco' => 'required|exists:saccos,id',
                'member' => 'required|exists:users,id',
                'status' => 'required|integer|min:0|max:1'
            ]);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }
            
            $saccoUser = SaccoUser::where('user_id', $request->member)->where('sacco_id', $request->sacco)
            ->where('end_date', null)->first();
            if($saccoUser == null){
                SaccoUser::where('user_id', $request->member)->where('id', '<>', $request->id)->where('end_date', null)
                ->update(['end_date'=>Carbon::now()]);
                
                $saccoUser = new SaccoUser;
                if ($request->id > 0) {
                    $saccoUser = SaccoUser::findOrFail($request->id);
                }else{
                    $saccoUser->start_date = Carbon::now();
                }
            }
            $saccoUser->sacco_id = $request->sacco;
            $saccoUser->user_id = $request->member;
            $saccoUser->status = $request->status;
            $saccoUser->created_by = Auth::user()->id;

            if ($saccoUser->save()) {
                SaccoUser::where('user_id', $request->member)->where('id', '<>',$saccoUser->id)
                ->where('end_date', null)->update(['end_date'=>Carbon::now(), 'status'=>0]);
                User::where('id', $request->member)->update(['sacco_id'=>$request->sacco]);
                return response()->json(['success' => 'Member updated successfully!']);
            } else {
                return response()->json(['error' => 'Unable to update member'], 401);
            }
        }else {
            return response()->json(['error' => 'Permissions to Add/Edit Member Denied'], 401);
        }

    }
   
}
