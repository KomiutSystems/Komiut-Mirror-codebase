<?php

namespace App\Http\Controllers\Dashboard\Users;

use App\Http\Controllers\Controller;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Yajra\DataTables\DataTables;

class RolesController extends Controller
{
    //
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(['permission:View Roles']);
    }
    public function index()
    {
        return view('dashboard.users.roles');
    }

    public function getRoles(Request $request)
    {
        return DataTables::of(Role::orderBy('name', 'ASC'))
            ->filter(function ($query) use ($request) {
                $query->where('name', 'LIKE', '%' . $request->search . '%');
            })->editColumn('created_at', function ($row) {
            return Carbon::parse($row->created_at)->diffForHumans();
        })->addColumn('users', function ($row) {
            return number_format(User::whereHas('roles', function ($query) use ($row) {
                $query->where('id', $row->id);
            })->count(), 0, ',', '.');
        })->addColumn('action', function ($row) {
            $actionBtn = '<div style="white-space: nowrap;" class="text-end">' .
                '<span class="d-none id">' . $row->id . '</span>' .
                '<span class="d-none name">' . $row->name . '</span>';
            if (auth()->user()->can('Edit Roles'))
                $actionBtn .= '<button class="btn-edit btn btn-primary btn-sm" data-toggle="modal" data-target="#userModal"><i class="fas fa-edit"></i> Edit</button> ';
            $actionBtn .= '<a href="' . url('users/roles/view/' . $row->id) . '" class="delete btn btn-outline-primary btn-sm"><i class="fas fa-eye"></i> View</a>'
                . '</div>';
            return $actionBtn;
        })->addIndexColumn()->escapeColumns([])->make();
    }
    public function addRole(Request $request)
    {
        if (auth()->user()->can("Edit Roles") || auth()->user()->can("Add Roles")) {
            $validator = Validator::make($request->all(), [
                'id' => 'required|integer|min:0',
                'name' => 'required|string|unique:roles,name,' . $request->id,
            ]);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }
            $role = new Role;
            if ($request->id > 0) {
                $role = Role::findOrFail($request->id);
            }
            $role->name = $request->name;
            if ($role->save()) {
                return response()->json(['success' => 'Role created successfully!']);
            } else {
                return response()->json(['error' => 'Unable to create role'], 401);
            }
        } else {
            return response()->json(['error' => 'Permissions to Add/Edit Role Denied'], 401);
        }
    }
    public function viewRole(Request $request)
    {
        $role = Role::where('id', $request->id)->first();
        if ($role == null) {
            return redirect()->to('home');
        }
        $permissions = Permission::with([
            'roles' => function ($query) use ($request) {
                $query->where('id', $request->id);
            }
        ])->where("name", 'NOT LIKE', '%permissions%')->get();
        //return json_encode($permissions);
        return view('dashboard.users.role', @compact('role', 'permissions'));
    }

    public function addPermissions(Request $request)
    {
        //if (auth()->user()->can("Edit Roles") || auth()->user()->can("Add Roles")) {
            $validator = Validator::make($request->all(), [
                'id' => 'required|integer|exists:roles,id',
                'permissions.*' => 'nullable|integer|exists:permissions,id',
            ]);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }

            $permissions = Permission::whereIn('id', $request->permissions != "" ? $request->permissions : [$request->permissions])->pluck("name");
            $role = Role::where('id', $request->id)->first();
            if ($role->syncPermissions($permissions)) {
                return response()->json(['success' => 'Permissions updated successfully!']);
            } else {
                return response()->json(['error' => 'Unable to update permissions'], 401);
            }
        /*} else {
            return response()->json(['error' => 'Permissions to Add/Edit Role Denied'], 401);
        }*/
    }
}