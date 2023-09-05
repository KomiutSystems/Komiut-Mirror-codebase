<?php

namespace App\Http\Controllers\APIs\Dashboard\Saccos;

use App\Http\Controllers\Controller;
use App\Models\SaccoUser;
use Illuminate\Http\Request;

class SaccoMembersAPIController extends Controller
{
    public function __construct(){
        $this->middleware('auth:api');
    }
    
    public function getMembers(Request $request){
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $saccoUsers = SaccoUser::with(['user', 'sacco']);
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
    }
}
