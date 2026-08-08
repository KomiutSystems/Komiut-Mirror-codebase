<?php

namespace App\Http\Controllers\APIs\Dashboard\Users;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class UsersAPIController extends Controller
{
    public function __construct(){
        $this->middleware('auth:sanctum');
    }

    public function getUsers(Request $request){

        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $from_date = $request->date != ""?Carbon::parse($request->date):"";
        $to_date = $from_date != ""?$from_date->copy()->addDays(1):"";

        $actor = $request->user();
        $users = User::with(['roles', 'gender', 'sacco']);
        // Non-superadmins only ever see their own SACCO's users, regardless of
        // any client-supplied `sacco`. Superadmins may pivot via `sacco`.
        if ($actor->isSuperAdmin()) {
            if ($request->sacco > 0) {
                $users = $users->where('sacco_id', $request->sacco);
            }
        } else {
            $users = $users->where('sacco_id', $actor->currentSaccoId());
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
        // Two fixes here.
        //
        // 1. Guarded: an empty box previously applied LIKE '%%' to four columns.
        //
        // 2. orWhere, not where. These were chained with ->where(), i.e. ANDed, so
        //    a real search demanded that firstname AND lastname AND phone AND email
        //    all matched the SAME string — which essentially never happens. User
        //    search returned nothing for any genuine query; it only "worked" while
        //    the term was empty and every LIKE '%%' matched. Every sibling
        //    controller ORs these, so this was a copy-paste slip, not intent.
        $users = $users->when(filled($request->search), fn ($builder) => $builder
            ->where(function($query)use($request){
                $query->where('firstname', 'LIKE', '%'.$request->search.'%')
                ->orWhere('lastname', 'LIKE', '%'.$request->search.'%')
                ->orWhere('phone', 'LIKE', '%'.$request->search.'%')
                ->orWhere('email', 'LIKE', '%'.$request->search.'%');
            }))->orderBy('created_at', 'DESC')->skip($offset)->take(20)->get();
        return response()->json(['users'=>$users]);
    }
}
