<?php

namespace App\Http\Controllers\Dashboard\Users;

use App\Http\Controllers\Controller;
use App\Models\Sacco;
use App\Models\SaccoUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;
use Yajra\DataTables\DataTables;

class UsersController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(['permission:View Users']);
    }
    public function index()
    {
        $sacco = Sacco::find(Auth::user()->sacco_id);
        return view('dashboard.users.users', @compact('sacco'));
    }
    public function getUsers(Request $request)
    {
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
        if ($request->from_date != "") {
            $users = $users->where('created_at', '>=', Carbon::parse($request->from_date));
        }
        if ($request->to_date != "") {
            $users = $users->where('created_at', '<=', Carbon::parse($request->to_date));
        }
        if ($request->status != "") {
            $users = $users->where('status', $request->status);
        }
        $users = $users->orderBy('firstname', 'ASC');
        return DataTables::of($users)
            ->filter(function ($query) use ($request) {
                $query->where(function ($q) use ($request) {
                    $q->where('firstname', 'LIKE', '%' . $request->search . '%')
                        ->orWhere('lastname', 'LIKE', '%' . $request->search . '%')
                        ->orWhere('email', 'LIKE', '%' . $request->search . '%')
                        ->orWhere('phone', 'LIKE', '%' . $request->search . '%');
                });
            })->editColumn('dob', function ($row) {
            return Carbon::parse($row->dob)->format('d M, Y');
        })->editColumn('created_at', function ($row) {
            return Carbon::parse($row->created_at)->diffForHumans();
        })->addColumn('role', function ($row) {
            return $row->roles->first()?->name ?? "Unknown";
        })->addColumn('status', function ($row) {
            return $row->status ? "<span class='badge bg-primary'>Active</span>" : "<span class='badge bg-danger'>In-Active</span>";
        })->addColumn('action', function ($row) {
            $actionBtn = '<div style="white-space: nowrap;" class="text-end">' .
                '<span class="d-none id">' . $row->id . '</span>' .
                '<span class="d-none firstname">' . $row->firstname . '</span>' .
                '<span class="d-none lastname">' . $row->lastname . '</span>' .
                '<span class="d-none email">' . $row->email . '</span>' .
                '<span class="d-none phone">' . $row->phone . '</span>' .
                '<span class="d-none status">' . $row->status . '</span>' .
                '<span class="d-none gender_id">' . $row->gender_id . '</span>' .
                '<span class="d-none role_id">' . $row->roles->first()?->id ?? null . '</span>' .
                '<span class="d-none gender_name">' . $row->gender->name . '</span>' .
                '<span class="d-none role_name">' . $row->roles->first()?->name ?? null . '</span>' .
                '<span class="d-none sacco">' . ($row->sacco_id > 0 ? $row->sacco->name : '') . '</span>' .
                '<span class="d-none sacco_id">' . $row->sacco_id . '</span>' .
                '<span class="d-none dob">' . $row->dob . '</span>';
            if (auth()->user()->can('Edit Users') )
                $actionBtn .= '<button class="btn-edit btn btn-primary btn-sm" data-toggle="modal" data-target="#userModal" '.( auth()->user()->id == $row->id?"disabled":"").'><i class="fas fa-edit"></i> Edit</button> ';
            $actionBtn .= '<!--<a href="javascript:void(0)" class="delete btn btn-outline-primary btn-sm"><i class="fas fa-eye"></i> View</a>-->'
                . '</div>';

            return $actionBtn;
        })->addIndexColumn()->escapeColumns([])->make();
    }
    public function addUser(Request $request)
    {
        if (auth()->user()->can('Edit Users') || auth()->user()->can('Add Users')) {
            $validator = Validator::make($request->all(), [
                'id' => 'required|integer|min:0',
                'firstname' => 'required|string',
                'lastname' => 'required|string',
                'dob' => 'required|date|before:today',
                'email' => 'required|string|unique:users,email,' . $request->id,
                'phone' => 'required|digits:10|unique:users,phone,' . $request->id,
                'status' => 'required|min:0|max:1|integer',
                'role' => 'required|min:1',
                'gender' => 'required|min:1',
                'sacco' => 'nullable|integer|min:1',
            ]);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }
            $actor = Auth::user();
            // Non-superadmins manage users only within their own SACCO, and may
            // only ever assign their own SACCO — never an arbitrary one.
            $targetSacco = $actor->isSuperAdmin() ? $request->sacco : $actor->currentSaccoId();

            $user = new User;
            if ($request->id > 0) {
                $user = User::findOrFail($request->id);
                if (! $actor->isSuperAdmin() && $user->sacco_id !== null && $user->sacco_id != $actor->currentSaccoId()) {
                    return response()->json(['error' => 'Not authorized to edit this user'], 403);
                }
            } else {
                $user->password = Hash::make('12345');
            }
            $user->firstname = $request->firstname;
            $user->dob = $request->dob;
            $user->lastname = $request->lastname;
            $user->phone = $request->phone;
            $user->email = $request->email;
            $user->gender_id = $request->gender;
            $user->sacco_id = $targetSacco;
            $user->status = $request->status;
            if ($user->save()) {
                $role = Role::where('id', $request->role)->first();
                if ($role != null) {
                    $user->syncRoles([$role->name]);
                }
                if ($targetSacco > 0) {
                    $saccoUser = SaccoUser::where('user_id', $user->id)->where('sacco_id', $targetSacco)->where('end_date', null)->first();
                    if ($saccoUser == null) {
                        SaccoUser::where('user_id', $user->id)->where('end_date', null)->update(['end_date' => Carbon::now()]);
                        $saccoUser = new SaccoUser;
                        $saccoUser->sacco_id = $targetSacco;
                        $saccoUser->user_id = $user->id;
                        $saccoUser->status = $request->status;
                        $saccoUser->start_date = Carbon::now();
                        $saccoUser->created_by = Auth::user()->id;
                        $saccoUser->save();
                    }
                }
                return response()->json(['success' => 'User updated successfully!']);
            } else {
                return response()->json(['error' => 'Unable to update user'], 401);
            }
        } else {
            return response()->json(['error' => 'Permissions to Add/Edit User denied'], 401);
        }
    }

    public function searchUser(Request $request)
    {
        $user = User::with(['sacco'])
        ->where(function($query) use($request){
            $query->where('firstname', 'LIKE', '%' . $request->q . '%')
            ->orWhere('email', 'LIKE', '%' . $request->q . '%')
            ->orWhere('firstname', 'LIKE', '%' . $request->q . '%')
            ->orWhere('lastname', 'LIKE', '%' . $request->q . '%');
        });
        if(Auth::user()->sacco_id > 0){
            $user = $user->where('sacco_id', Auth::user()->sacco_id);
        }
        $user = $user->skip(0)->take(5)->get();
        return json_encode($user);
    }
}
