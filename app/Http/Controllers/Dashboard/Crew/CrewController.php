<?php

namespace App\Http\Controllers\Dashboard\Crew;

use App\Http\Controllers\Controller;
use App\Models\Crew;
use App\Models\Sacco;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Yajra\DataTables\DataTables;

class CrewController extends Controller
{

    public function __construct(){
        $this->middleware('auth');
        $this->middleware(['permission:View Crews']);
    }
    public function index(){
        $sacco = Sacco::find(Auth::user()->sacco_id);
        return view('dashboard.crew.crews', @compact('sacco'));
    }
    public function getCrews(Request $request){
        $crew = Crew::with('user.sacco');
        if($request->sacco > 0){
            $crew = $crew->whereHas('user.sacco', function($q) use($request){
                $q->where('sacco_id',$request->sacco);
            });
        }

        if($request->date != ""){
            $crew = $crew->whereDate("created_at", $request->date);
        }
        return DataTables::of($crew)
            ->filter(function($query) use($request){
                $query->where(function($q) use($request){
                    $q->whereHas('user',function($query) use($request){
                        $query->where(DB::Raw('concat(firstname, " ", lastname)'),'LIKE', '%'.$request->search.'%')
                        ->where('email','LIKE', '%'.$request->search.'%')->where('phone','LIKE', '%'.$request->search.'%');
                    })->orWhere('phone', 'LIKE', '%'.$request->search.'%')->orWhere(DB::Raw('concat(firstname, " ", lastname)'), 'LIKE', '%'.$request->search.'%')
                    ->orWhere('id_number', 'LIKE', '%'.$request->search.'%')->orWhere('badge_number', 'LIKE', '%'.$request->search.'%');
                })->where('status', $request->status);
            })->editColumn('created_at', function ($row) {
                return Carbon::parse($row->created_at)->diffForHumans();
            })->addColumn('action', function ($row) {
                $actionBtn = '<div style="white-space: nowrap;" class="text-end">' .
                    '<span class="d-none id">' . $row->id . '</span>' .
                    '<span class="d-none firstname">' . $row->firstname . '</span>' .
                    '<span class="d-none lastname">' . $row->lastname . '</span>' .
                    '<span class="d-none id_number">' . $row->id_number . '</span>' .
                    '<span class="d-none badge_number">' . $row->badge_number . '</span>' .
                    '<span class="d-none phone">' . $row->phone . '</span>' .
                    '<span class="d-none email">' . $row->email . '</span>' .
                    '<span class="d-none user">' . $row->user->firstname.' '.$row->user->lastname . ' ('.$row->user->email.')</span>' .
                    '<span class="d-none user_id">' . $row->user_id . '</span>' .
                    '<span class="d-none status">' . $row->status . '</span>';
                    if(auth()->user()->can('Edit Crews'))
                        $actionBtn .= '<button class="btn-edit btn btn-primary btn-sm" data-toggle="modal" data-target="#routeModal"><i class="fas fa-edit"></i> Edit</button> ';
                    $actionBtn .= '<!--<a href="' . url('/queues/view/' . $row->queue_id) . '" class="btn btn-outline-primary btn-sm"><i class="fas fa-eye"></i> View</a>-->'
                    . '</div>';
                return $actionBtn;
            })->addIndexColumn()->escapeColumns([])->make();
    }

    public function addCrew(Request $request){
        if(auth()->user()->can('Add Crews') || auth()->user()->can('Edit Crews')){
            $validator = Validator::make($request->all(), [
                'id'=>'required|integer|min:0',
                'firstname'=>'required|string',
                'lastname'=>'required|string',
                'phone'=>'required|digits:10|unique:crews,phone,'.$request->id,
                'id_number'=>'required|digits:8|unique:crews,phone,'.$request->id,
                'user'=>'required|exists:users,id',
                'badge_number'=>'required|string',
                'email'=>'nullable|email',
                'status'=>'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }
            //check seat availability
            $crew = new Crew;
            if($request->id > 0){
                $crew = Crew::find($request->id);
            }else{
                $crew->password = Hash::make('12345');
            }
            $crew->firstname = $request->firstname;
            $crew->lastname = $request->lastname;
            $crew->id_number = $request->id_number;
            $crew->badge_number = $request->badge_number;
            $crew->phone = $request->phone;
            $crew->email = $request->email;
            $crew->user_id = $request->user;
            $crew->created_by = Auth::user()->id;
            $crew->status = $request->status;
            if($crew->save()){
                return response()->json(['success'=>"Crew updated successfully!"]);
            }else{
                return response()->json(['error'=>'Unable to update crew'], 401);
            }
        }else{
            return response()->json(['error'=>'You do not have permissions Add/Edit Crew'], 401);
        }
    }
}
