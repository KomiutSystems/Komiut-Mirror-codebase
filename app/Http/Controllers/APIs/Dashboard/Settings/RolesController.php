<?php

namespace App\Http\Controllers\APIs\Dashboard\Settings;

use App\Auth\Roles;
use App\Http\Controllers\Concerns\GrantsRoles;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Super\Access\AccessChangeRecorder;
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
    use GrantsRoles;

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
        // Snapshot BEFORE the sync so the access-domain recorder can diff.
        $permsBefore = $role->wasRecentlyCreated ? [] : $role->permissions()->pluck('name')->all();
        $role->syncPermissions(
            Permission::where('guard_name', 'web')->whereIn('name', $data['permissions'] ?? [])->get()
        );
        app(AccessChangeRecorder::class)
            ->recordPermissionSync($role, $permsBefore, auth()->user());

        return response()->json(['role' => [
            'name' => $role->name,
            'permissions' => $role->permissions()->pluck('name')->values(),
        ]]);
    }

    /**
     * Assign roles to a SACCO member
     *
     * A member may hold several roles and gets every permission of each. The
     * list sent REPLACES the member's roles, so send all they should end with.
     *
     * A SACCO admin may assign roles only to a member of their OWN SACCO, only
     * from the shared SACCO-tier roles, and only roles whose permissions they
     * themselves hold (no privilege escalation) -- checked over every role the
     * member holds before and after, since roles combine. Roles outside the
     * SACCO tier that the member holds are kept, never removed by a SACCO
     * admin. An admin limited to their own or their bank's buses may not
     * change roles at all.
     * Superadmins are unrestricted.
     *
     * @authenticated
     *
     * @urlParam user integer required The member's user id. Example: 5
     *
     * @bodyParam roles string[] required Every role the member should hold. Example: ["Finance","Fleet Manager"]
     */
    public function assignMemberRoles(Request $request, User $user)
    {
        $data = Validator::make($request->all(), [
            'roles' => 'required|array|min:1',
            'roles.*' => 'string|exists:roles,name',
        ])->validate();

        $caller = auth()->user();
        $roles = array_values(array_unique($data['roles']));
        $held = $user->getRoleNames()->all();

        if (! $this->isSuper()) {
            // 1) Same SACCO only. User is NOT SaccoScoped, so route-model binding
            //    loads any user by id — this check is the real tenant boundary.
            if ($user->sacco_id === null || $user->sacco_id !== $caller->currentSaccoId()) {
                abort(403, 'You can only manage members of your own SACCO.');
            }
            if (! $caller->can('Edit Sacco Members')) {
                abort(403, 'You do not have permission to manage members.');
            }
            // An account limited to its own buses or its bank's must not hand
            // out roles: its permission NAMES may cover a fleet-wide role while
            // its reach does not, so the ceiling below cannot catch it.
            if ($why = $this->limitedRoleGranter()) {
                abort(403, $why);
            }
            // Roles outside the SACCO tier that the member already holds (Bank
            // Viewer, given by a platform admin) are not on a SACCO admin's
            // screen to tick. The list REPLACES the member's roles, so they are
            // kept as they are -- never dropped, never granted here.
            $kept = array_values(array_diff($held, Roles::saccoAssignable()));
            $roles = array_values(array_diff($roles, $kept));

            // 2) No platform / non-assignable roles.
            $illegal = array_values(array_diff($roles, Roles::saccoAssignable()));
            if ($illegal !== []) {
                abort(403, 'You cannot assign these roles: '.implode(', ', $illegal));
            }

            $roles = [...$roles, ...$kept];

            // 3) Permission ceiling, before AND after. Roles combine, and one
            //    can widen another's reach -- adding Fleet Manager lifts an
            //    Investor's own-buses limit, removing Investor lifts it for
            //    everything else they hold -- so the member must end with no
            //    permission the admin lacks. And the list replaces their roles,
            //    so a member who already has more access than the admin (a SACCO
            //    Admin, to a Fleet Manager) is not theirs to change at all.
            //    Permissions given directly, not through a role, count too.
            $mine = $caller->getAllPermissions()->pluck('name');
            $direct = $user->getDirectPermissions()->pluck('name');
            $reach = fn (array $names) => Role::where('guard_name', 'web')->whereIn('name', $names)
                ->with('permissions:id,name')->get()
                ->flatMap(fn (Role $r) => $r->permissions->pluck('name'))->merge($direct)->unique();

            $beyond = $reach($held)->diff($mine);
            if ($beyond->isNotEmpty()) {
                abort(403, 'This member has access you do not have ('.$beyond->implode(', ')
                    .'), so only someone who has it too can change their roles.');
            }
            $exceeding = $reach($roles)->diff($mine);
            if ($exceeding->isNotEmpty()) {
                abort(403, 'These roles exceed your own permissions: '.$exceeding->implode(', '));
            }
        }

        // Snapshot BEFORE the sync so the access-domain recorder can diff role /
        // permission changes (Super Admin grant, sensitive permission gained).
        $rolesBefore = $held;
        $permsBefore = $user->getAllPermissions()->pluck('name')->all();

        // Sync by model (guard 'web'), not by name — see saveRole for why.
        $user->syncRoles(Role::where('guard_name', 'web')->whereIn('name', $roles)->get());

        app(AccessChangeRecorder::class)
            ->recordRoleSync($user, $rolesBefore, $permsBefore, auth()->user());

        return response()->json(['user' => [
            'id' => $user->id,
            'roles' => $user->getRoleNames()->values(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
        ]]);
    }
}
