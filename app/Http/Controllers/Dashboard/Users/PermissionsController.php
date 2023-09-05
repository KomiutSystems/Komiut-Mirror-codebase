<?php

namespace App\Http\Controllers\Dashboard\Users;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Permission;
use Yajra\DataTables\DataTables;

class PermissionsController extends Controller
{
    //
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(['permission:View Permissions']);
    }
    public function index()
    {
        return view('dashboard.users.permissions');
    }

    public function getPermissions(Request $request)
    {
        return DataTables::of(Permission::orderBy('created_at', 'ASC'))
            ->filter(function ($query) use ($request) {
                $query->where('name', 'LIKE', '%' . $request->search . '%');
            })->editColumn('created_at', function ($row) {
            return Carbon::parse($row->created_at)->diffForHumans();
        })->addColumn('action', function ($row) {
            $actionBtn = '<div style="white-space: nowrap;" class="text-end">' .
                '<span class="d-none id">' . $row->id . '</span>' .
                '<span class="d-none name">' . $row->name . '</span>';
            if (auth()->user()->can('Edit Permissions'))
                $actionBtn .= '<button class="btn-edit btn btn-primary btn-sm" data-toggle="modal" data-target="#userModal"><i class="fas fa-edit"></i> Edit</button> ';
            $actionBtn .= '<!--<a href="javascript:void(0)" class="delete btn btn-outline-primary btn-sm"><i class="fas fa-eye"></i> View</a>-->'
                . '</div>';
            return $actionBtn;
        })->addIndexColumn()->escapeColumns([])->make();
    }
    public function addPermission(Request $request)
    {
        if (auth()->user()->can('Edit Permissions') || auth()->user()->can('Add Permissions')) {
            $validator = Validator::make($request->all(), [
                'id' => 'required|integer|min:0',
                'name' => 'required|string|unique:permissions,name,' . $request->id,
            ]);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }
            $permission = new Permission;
            if ($request->id > 0) {
                $permission = Permission::findOrFail($request->id);
            }
            $permission->name = $request->name;
            if ($permission->save()) {
                return response()->json(['success' => 'Permission created successfully!']);
            } else {
                return response()->json(['error' => 'Unable to create permission'], 401);
            }
        } else {
            return response()->json(['error' => 'Permissions to Add/Edit Permissions Denied'], 401);
        }
    }

}