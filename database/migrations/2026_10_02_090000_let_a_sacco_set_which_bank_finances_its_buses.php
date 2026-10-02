<?php

declare(strict_types=1);

use App\Auth\Roles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create the 'Edit Vehicle Bank' permission and give it to SACCO Admin and
 * Super Admin.
 *
 * Until now only a superadmin could set `vehicles.financier`, and the SACCO —
 * the one party that actually knows which bank financed which bus — could not
 * even correct it. NICCO MOVERS runs 126 NCBA and 54 Co-op buses in one fleet,
 * 720 vehicles across the platform carry no bank at all, and the conversation
 * with the SACCO kept ending at "we cannot tell which bus is under which bank".
 * POST vehicles/add now lets a holder of this permission set, change or clear
 * the bank of a bus in their own SACCO, with every actual change audited and
 * raised on the super console.
 *
 * A MIGRATION rather than a seeder edit alone, for the same reason as
 * 2026_09_03_090000: deploy runs `migrate --force` and never `db:seed`, so
 * adding the permission to Roles::FEATURE_PERMISSIONS changes nothing in
 * production by itself. And deliberately TARGETED: RoleSeeder calls
 * syncPermissions on every role, which would silently revert any permission
 * granted by hand since the last seed. This touches one permission and two
 * roles, and only adds.
 *
 * SACCO Admin is "every permission except Roles::PLATFORM_ONLY", which is what
 * the seeder would have given it; Fleet Manager, Investor and the other
 * granular bundles are left alone on purpose — moving a bus between banks moves
 * its money between two banks' views, and that is not a fleet clerk's call.
 *
 * Idempotent: the permission is firstOrCreate'd, each grant is skipped when
 * already held, and a role that does not exist (a fresh database, the test
 * suite) is skipped rather than conjured — a role created here would carry
 * this one permission and nothing else.
 */
return new class extends Migration
{
    private const PERMISSION = 'Edit Vehicle Bank';

    private const GUARD = 'web';

    /** @var array<int, string> */
    private const GRANTED_TO = [Roles::SACCO_ADMIN, Roles::SUPER_ADMIN];

    public function up(): void
    {
        $permission = Permission::firstOrCreate([
            'name' => self::PERMISSION,
            'guard_name' => self::GUARD,
        ]);

        foreach ($this->roles() as $role) {
            if (! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permission = Permission::where('name', self::PERMISSION)
            ->where('guard_name', self::GUARD)
            ->first();

        if ($permission === null) {
            return;
        }

        foreach ($this->roles() as $role) {
            if ($role->hasPermissionTo($permission)) {
                $role->revokePermissionTo($permission);
            }
        }

        // Delete the permission itself only when nothing else still holds it.
        // A superadmin may have granted it by hand to another role or straight
        // to a user since this ran; deleting the row would cascade those grants
        // away with no record of who had them. Left in place it is harmless —
        // an unheld permission gates nothing.
        $stillHeld = DB::table(config('permission.table_names.role_has_permissions', 'role_has_permissions'))
            ->where('permission_id', $permission->id)
            ->exists()
            || DB::table(config('permission.table_names.model_has_permissions', 'model_has_permissions'))
                ->where('permission_id', $permission->id)
                ->exists();

        if (! $stillHeld) {
            $permission->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @return array<int, Role> */
    private function roles(): array
    {
        return Role::whereIn('name', self::GRANTED_TO)
            ->where('guard_name', self::GUARD)
            ->get()
            ->all();
    }
};
