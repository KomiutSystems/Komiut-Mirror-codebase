<?php

namespace App\Http\Controllers\APIs\Dashboard\Users;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class UsersAPIController extends Controller
{
    public function __construct(){
        $this->middleware('auth:api');
    }
    
    public function getUsers(Request $request){
        
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $from_date = $request->date != ""?Carbon::parse($request->date):"";
        $to_date = $from_date != ""?$from_date->copy()->addDays(1):"";

        $users = User::with(['roles', 'gender', 'sacco']);
        if ($request->sacco > 0) {
            $users = $users->where('sacco_id', $request->sacco);
        }
        if ($request->role > 0) {
            $users = $users->whereHas('roles', function ($query) use ($request) {
                $query->where('id', $request->role);
            });
        }
        if ($request->gender > 0) {
            $users = $users->where('gender_id', $request->gender);
        }
        if ($from_date != "") {
            $users = $users->whereBetween('created_at',[$from_date, $to_date]);
        }
        $users = $users->where(function($query)use($request){
            $query->where('firstname', 'LIKE', '%'.$request->search.'%')
            ->where('lastname', 'LIKE', '%'.$request->search.'%')
            ->where('phone', 'LIKE', '%'.$request->search.'%')
            ->where('email', 'LIKE', '%'.$request->search.'%');
        })->orderBy('created_at', 'DESC')->skip($offset)->take(20)->get();
        return response()->json(['users'=>$users]);
    }
}
