<?php

declare(strict_types=1);

use App\Auth\Roles;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The School Admin role, created where production gets it: deploys run
 * migrations, never seeders. Registration also creates the role on first use,
 * but it must exist before then -- so a superadmin can move an existing
 * SACCO's admins onto it (Virginia Coach, 2026-11-25) and the dashboard's role
 * picker lists it.
 *
 * Only grants what the role is missing, never syncs: a later hand grant is not
 * reverted by a re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Only where roles are already in use (production). A database with no
        // roles at all -- tests, a fresh machine -- gets them from RoleSeeder,
        // like every other role; conjuring one here would be the only role in
        // an otherwise unseeded database.
        if (Role::query()->count() === 0) {
            return;
        }

        $role = Role::firstOrCreate(['name' => Roles::SCHOOL_ADMIN, 'guard_name' => 'web']);

        foreach (Roles::bundles()[Roles::SCHOOL_ADMIN] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            if (! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $role = Role::where('name', Roles::SCHOOL_ADMIN)->where('guard_name', 'web')->first();

        // Never pull a role out from under someone who holds it.
        if ($role !== null && $role->users()->count() === 0) {
            $role->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
