<?php

namespace Database\Seeders;

use App\Auth\Roles;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the RBAC roles as bundles of permissions (idempotent).
 *
 * Runs after PermissionSeeder. Super Admin gets every permission; SACCO Admin
 * gets everything except the platform-only set; the remaining roles get their
 * explicit bundles from App\Auth\Roles. Any permission a bundle references is
 * created if missing, so this seeder is self-sufficient (works even without the
 * CSV). The spatie cache is flushed at the end — otherwise freshly-synced
 * permissions are served stale for the rest of the cache TTL.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $guard = config('auth.defaults.guard', 'web');

        // Ensure every permission any role references exists (self-sufficient seed).
        collect(Roles::bundles())->flatten()
            ->merge(Roles::PLATFORM_ONLY)
            ->merge(Roles::FEATURE_PERMISSIONS)
            ->unique()
            ->each(fn ($name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard]));

        $all = Permission::pluck('name')->all();

        // Super Admin — everything.
        $this->syncRole(Roles::SUPER_ADMIN, $all, $guard);

        // SACCO Admin — everything except platform-only permissions.
        $this->syncRole(Roles::SACCO_ADMIN, array_values(array_diff($all, Roles::PLATFORM_ONLY)), $guard);

        // Granular roles — explicit bundles.
        foreach (Roles::bundles() as $role => $permissions) {
            $this->syncRole($role, $permissions, $guard);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function syncRole(string $name, array $permissions, string $guard): void
    {
        Role::firstOrCreate(['name' => $name, 'guard_name' => $guard])
            ->syncPermissions($permissions);
    }
}
