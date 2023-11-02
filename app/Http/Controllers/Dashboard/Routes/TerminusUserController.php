<?php

namespace App\Http\Controllers\Dashboard\Routes;

use App\Http\Controllers\Controller;
use App\Models\TerminusUser;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Yajra\DataTables\DataTables;

class TerminusUserController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(['permission:View Termini Users']);
    }
    public function index(){
        return view('dashboard/routes/termini_users');
    }
    public function getTerminiUsers(Request $request)
    {
        $terminiUsers = TerminusUser::with(['terminus', 'user.sacco']);
        if(strlen($request->sacco) > 0){
            $terminiUsers = $terminiUsers->whereHas('user', function($query) use($request){
                $query->where('sacco_id',$request->sacco);
            });
        }
        if(strlen($request->terminus) > 0){
            $terminiUsers = $terminiUsers->where('terminus_id',$request->terminus);
        }
        if(strlen($request->place) > 0){
            $terminiUsers = $terminiUsers->whereHas('terminus',function($query) use($request){
                $query->where('place_id', $request->place);
            });
        }
        $terminiUsers = $terminiUsers->orderBy('id', 'DESC');
        return DataTables::of($terminiUsers)
            ->filter(function ($query) use ($request) {
                $query->where(function($query) use ($request){
                    $query->whereHas('user', function($query) use ($request){
                        $query->where('firstname', 'LIKE', '%'.$request->search.'%')
                        ->orWhere('lastname', 'LIKE', '%'.$request->search.'%')
                        ->orWhere('email', 'LIKE', '%'.$request->search.'%')
                        ->orWhere('phone', 'LIKE', '%'.$request->search.'%');
                    });
                });
            })->editColumn('created_at', function ($row) {
            return Carbon::parse($row->created_at)->diffForHumans();
        })->addColumn('action', function ($row) {
            $actionBtn = '<div style="white-space: nowrap;" class="text-end">' .
                '<span class="d-none id">' . $row->id . '</span>' .
                '<span class="d-none terminus_id">' . $row->terminus_id . '</span>' .
                '<span class="d-none terminus">' . $row->terminus->name . '</span>' .
                '<span class="d-none user">' . $row->user->firstname.' '.$row->user->lastname." (".$row->user->email." | ".$row->user->phone.")" . '</span>' .
                '<span class="d-none user_id">' . $row->id . '</span>' .
                '<span class="d-none status">' . $row->status . '</span>' ;
            if (auth()->user()->can('Edit Queues'))
                $actionBtn .= '<button class="btn-edit btn btn-primary btn-sm" data-toggle="modal" data-target="#routeModal"><i class="fas fa-edit"></i> Edit</button> ';
            /*$actionBtn .= '<a href="' . url('/queues/view/' . $row->id) . '" class="btn btn-outline-primary btn-sm"><i class="fas fa-eye"></i> View</a>'
                . '</div>';*/
            return $actionBtn;
        })->addIndexColumn()->escapeColumns([])->make();
    }
    public function addTerminusUser(Request $request){
        if(auth()->user()->can('Edit Termini Users') || auth()->user()->can('Add Termini Users')){
            $validator = Validator::make($request->all(), [
                'id'=>'required|integer|min:0',
                'user' => 'required|integer|exists:users,id',
                'terminus' => 'required|integer|exists:termini,id',
                'status' => 'required|integer|min:0|max:1',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }
            if(TerminusUser::where('terminus_id', $request->terminus)->where('user_id', $request->user)
            ->where('id', '<>',$request->id)->count() > 0){
                return response()->json(['error'=>'Terminus User already exists'], 401);
            }
            $terminusUser = new TerminusUser();
            if($request->id > 0){
                $terminusUser = TerminusUser::findOrFail($request->id);
            }
            $terminusUser->terminus_id = $request->terminus;
            $terminusUser->user_id = $request->user;
            $terminusUser->status = $request->status;
            if($terminusUser->save()){
                return response()->json(['success'=>"Terminus User updated successfully!"]);
            }else{
                return response()->json(['error'=>'Unable to update user terminus'], 401);
            }
        }else{
            return response()->json(['error'=>'You do not have permissions for this action'], 401);
        }
    }
}
