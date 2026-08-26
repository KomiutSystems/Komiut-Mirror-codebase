<?php

namespace App\Http\Controllers\APIs\Dashboard\Users;

use App\Http\Controllers\Controller;
use App\Services\Sql\LikeSql;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleAPIController extends Controller
{
    public function __construct(){
        $this->middleware('auth:sanctum');
    }

    public function getRoles(Request $request){
        $roles = Role::orderBy('name', 'ASC');
        if($request->filled("search")){
            $roles = $roles->where('name', LikeSql::op(), '%'.$request->search.'%');
        }
        $roles = $roles->paginate($request->per_page ?? 10);
        return response()->json(['roles'=>$roles]);
    }

    /**
     * Create or rename a role.
     *
     * The permission check is here AND on the route, mirroring addPermissions().
     * It had neither. Every sibling write on this controller checked
     * can('Add Roles')/can('Edit Roles') — both PLATFORM_ONLY — and this one went
     * straight from validation to saving, so any authenticated caller could
     * reach it: a passenger, a driver, anyone holding a token.
     *
     * Renaming is what makes that severe rather than untidy. Spatie matches roles
     * by NAME, so renaming an existing SACCO-tier role to "Super Admin" grants
     * the platform to everyone already holding it — no permission ever assigned,
     * no role ever granted.
     */
    public function addRole(Request $request)
    {
        if (! Auth::user()->can('Add Roles') && ! Auth::user()->can('Edit Roles')) {
            return response()->json(['error' => 'You are not allowed to manage roles.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'id'               => 'required|integer|min:0',
            'name'             => 'required|string|max:255',
            //'can_self_register'=> 'integer|min:0|max:1',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $roleName = $request->name;

        if (Role::where('name', $roleName)->where('id', '<>', $request->id)->exists()) {
            return response()->json(['error' => 'Role already exists'], 401);
        }

        $role = $request->id > 0 ? Role::find($request->id) : new Role();

        // An unknown id used to fall straight through to `$role->name = ...` on
        // null — a 500 where a 404 belongs.
        if ($role === null) {
            return response()->json(['error' => 'Role not found'], 404);
        }

        $role->name             = $roleName;
        //$role->display_name     = $request->name;
        //$role->can_self_register = $request->can_self_register;
        $role->guard_name       = 'web';
        
        if ($role->save()) {
            return response()->json(['success' => 'Role updated successfully!']);
        }
        return response()->json(['error' => 'Unable to update role'], 401);
    }

    public function role(Request $request)
    {

        $role = Role::where('id', $request->id)->first();
        if ($role == null) {
            // 404 JSON, not redirect()->to('home'). This is a JSON API: a redirect
            // hands the client a 302 to a page that does not exist in an API-only
            // service, so the caller sees a confusing HTML-ish failure instead of
            // a clear "not found" it can act on.
            return response()->json(['error' => 'Role not found'], 404);
        }
        $permissions = Permission::with([
            'roles' => function ($query) use ($request) {
                $query->where('id', $request->id);
            }
        ])->where("name", 'NOT LIKE', '%permissions%')->get();
        return response()->json(['role'=>$role, 'permissions'=>$permissions]);
    }

    public function addPermissions(Request $request)
    {
        if (Auth::user()->can("Edit Roles") || Auth::user()->can("Add Roles")) {
        //\Log::info($request->all());
            $validator = Validator::make($request->all(), [
                'id' => 'required|integer|exists:roles,id',
                'permissions.*' => 'nullable|integer|exists:permissions,id',
            ]);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }

            // Normalise to an array. The previous expression fell back to
            // [$request->permissions], so clearing every permission passed
            // [null] / [""] into whereIn rather than an empty set.
            $ids = array_filter((array) $request->input('permissions', []), static fn ($id) => $id !== null && $id !== '');
            $permissions = $ids === [] ? [] : Permission::whereIn('id', $ids)->pluck('name')->all();

            $role = Role::where('id', $request->id)->first();

            // syncPermissions() returns the ROLE, which is always truthy, so the
            // old `if (...) else` could never report a failure — a genuine error
            // would have been reported as success. It throws on failure instead,
            // so catch that and surface it.
            try {
                $role->syncPermissions($permissions);
            } catch (\Throwable $e) {
                report($e);

                return response()->json(['error' => 'Unable to update permissions'], 500);
            }

            return response()->json(['success' => 'Permissions updated successfully!']);
        } else {
            return response()->json(['error' => 'Permissions to Add/Edit Role Denied'], 401);
        }
    }
}
