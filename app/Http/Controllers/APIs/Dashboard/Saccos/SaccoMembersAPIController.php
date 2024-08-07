<?php

namespace App\Http\Controllers\APIs\Dashboard\Saccos;

use App\Http\Controllers\Controller;
use App\Models\SaccoUser;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class SaccoMembersAPIController extends Controller
{
    public function __construct(){
        $this->middleware('auth:sanctum');
    }

    public function getMembers(Request $request){
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $saccoUsers = SaccoUser::with(['user.roles', 'sacco', 'user.gender']);
        if($request->sacco > 0){
            $saccoUsers = $saccoUsers->where('sacco_id', $request->sacco);
        }
        $saccoUsers = $saccoUsers->whereHas('user',function($query) use($request){
            $query->where('firstname', 'LIKE', '%'.$request->search.'%')
            ->orWhere('lastname', 'LIKE', '%'.$request->search.'%')
            ->orWhere('email', 'LIKE', '%'.$request->search.'%')
            ->orWhere('phone', 'LIKE', '%'.$request->search.'%');
        });
        $saccoUsers = $saccoUsers->skip($offset)->take(20)
        ->orderBy('created_at', 'DESC')->get();
        return response()->json(['sacco_users'=>$saccoUsers]);
    }public function addMember(Request $request)
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
