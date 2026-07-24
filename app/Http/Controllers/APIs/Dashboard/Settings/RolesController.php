<?php

namespace App\Http\Controllers\APIs\Dashboard\Settings;

use App\Auth\Roles;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * @group Roles & permissions
 *
 * The dashboard renders per-permission — the login response already returns the
 * signed-in user's `permissions`, and the UI shows/hides modules off that.
 *
 * Roles are global (no per-SACCO custom roles yet), so **role CRUD is
 * superadmin-only**: a created role would otherwise be visible to every SACCO.
 * SACCO admins get an **assign-only** surface — they may place their own staff
 * on the shared SACCO-tier roles, and only on roles whose permissions they
 * themselves hold.
 */
class RolesController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    private function isSuper(): bool
    {
        return (bool) optional(auth()->user())->isSuperAdmin();
    }

    /**
     * List assignable roles
     *
     * Superadmins see every role; SACCO admins see only the shared SACCO-tier
     * roles they may assign to staff. Each role carries its permission list.
     *
     * @authenticated
     */
    public function roles()
    {
        $query = Role::with('permissions:id,name')->orderBy('name');
        if (! $this->isSuper()) {
            $query->whereIn('name', Roles::saccoAssignable());
        }

        $roles = $query->get()->map(fn (Role $r) => [
            'name' => $r->name,
            'permissions' => $r->permissions->pluck('name')->values(),
        ]);

        return response()->json(['roles' => $roles]);
    }

    /**
     * List the permission catalog
     *
     * Grouped by module (the noun after the View/Add/Edit verb) for building a
     * role editor.
     *
     * @authenticated
     */
    public function permissions()
    {
        $grouped = Permission::orderBy('name')->pluck('name')
            ->groupBy(fn (string $name) => trim(preg_replace('/^(View|Add|Edit|Get|Delete)\s+/', '', $name)));

        return response()->json(['permissions' => $grouped]);
    }

    /**
     * Create or update a role (superadmin)
     *
     * Superadmin-only: a created role is global (visible to every SACCO) until
     * per-SACCO custom roles land.
     *
     * @authenticated
     *
     * @bodyParam name string required Role name. Example: Cashier
     * @bodyParam permissions string[] required Permission names to grant. Example: ["View Dashboard","View Transactions"]
     */
    public function saveRole(Request $request)
    {
        if (! $this->isSuper()) {
            abort(403, 'Superadmin only.');
        }

        $data = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'permissions' => 'array',
            'permissions.*' => 'string|exists:permissions,name',
        ])->validate();

        // Sanctum sets the default guard to 'sanctum' for the request, so syncing
        // permissions BY NAME would re-resolve them under 'sanctum' and find none.
        // Everything is seeded under 'web' (as PermissionSeeder does) — fetch the
        // 'web' permission models explicitly so no name→guard resolution happens.
        $role = Role::firstOrCreate(['name' => $data['name'], 'guard_name' => 'web']);
        $role->syncPermissions(
            Permission::where('guard_name', 'web')->whereIn('name', $data['permissions'] ?? [])->get()
        );

        return response()->json(['role' => [
            'name' => $role->name,
            'permissions' => $role->permissions()->pluck('name')->values(),
        ]]);
    }

    /**
     * Assign roles to a SACCO member
     *
     * A SACCO admin may assign roles only to a member of their OWN SACCO, only
     * from the shared SACCO-tier roles, and only roles whose permissions they
     * themselves hold (no privilege escalation). Superadmins are unrestricted.
     *
     * @authenticated
     *
     * @urlParam user integer required The member's user id. Example: 5
     * @bodyParam roles string[] required Role names to set on the member. Example: ["Fleet Manager"]
     */
    public function assignMemberRoles(Request $request, User $user)
    {
        $data = Validator::make($request->all(), [
            'roles' => 'required|array|min:1',
            'roles.*' => 'string|exists:roles,name',
        ])->validate();

        $caller = auth()->user();
        $roles = $data['roles'];

        if (! $this->isSuper()) {
            // 1) Same SACCO only. User is NOT SaccoScoped, so route-model binding
            //    loads any user by id — this check is the real tenant boundary.
            if ($user->sacco_id === null || $user->sacco_id !== $caller->currentSaccoId()) {
                abort(403, 'You can only manage members of your own SACCO.');
            }
            if (! $caller->can('Edit Sacco Members')) {
                abort(403, 'You do not have permission to manage members.');
            }
            // 2) No platform / non-assignable roles.
            $illegal = array_values(array_diff($roles, Roles::saccoAssignable()));
            if ($illegal !== []) {
                abort(403, 'You cannot assign these roles: ' . implode(', ', $illegal));
            }
            // 3) Permission ceiling — cannot grant beyond your own permissions.
            $granting = Role::where('guard_name', 'web')->whereIn('name', $roles)->with('permissions:id,name')->get()
                ->flatMap(fn (Role $r) => $r->permissions->pluck('name'))->unique();
            $exceeding = $granting->diff($caller->getAllPermissions()->pluck('name'));
            if ($exceeding->isNotEmpty()) {
                abort(403, 'These roles exceed your own permissions: ' . $exceeding->implode(', '));
            }
        }

        // Sync by model (guard 'web'), not by name — see saveRole for why.
        $user->syncRoles(Role::where('guard_name', 'web')->whereIn('name', $roles)->get());

        return response()->json(['user' => [
            'id' => $user->id,
            'roles' => $user->getRoleNames()->values(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
        ]]);
    }
}
