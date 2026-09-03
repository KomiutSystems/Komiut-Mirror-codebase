<?php

declare(strict_types=1);

use App\Auth\Roles;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Give the Bank Viewer role 'View Vehicles'.
 *
 * A bank was shown its collections but not the buses they came from, so the
 * fleet screen answered 403 and the money on the other screens was a total the
 * bank could not break down. Seeing which vehicles it financed is the same
 * question as seeing what they collected.
 *
 * This is a MIGRATION rather than a seeder run because deploy runs
 * `migrate --force` and never `db:seed`, so an edit to Roles::bundles() alone
 * changes nothing in production. It is also deliberately TARGETED: RoleSeeder
 * calls syncPermissions on every role, which would silently revert any
 * permission granted by hand since the last seed. This touches one role and one
 * permission.
 *
 * No boundary widens. Vehicle carries BelongsToFinancier, so FinancierScope has
 * already confined the query before the controller sees it, and 'View Vehicles'
 * gates exactly one route (GET /vehicles). A bank viewer whose financier column
 * is unset resolves to null and FinancierScope denies everything, so this grant
 * shows them nothing rather than everything.
 */
return new class extends Migration
{
    private const PERMISSION = 'View Vehicles';

    public function up(): void
    {
        $role = $this->bankViewer();

        if ($role === null) {
            return;
        }

        $permission = Permission::firstOrCreate([
            'name' => self::PERMISSION,
            'guard_name' => 'web',
        ]);

        if (! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $role = $this->bankViewer();

        if ($role === null) {
            return;
        }

        $permission = Permission::where('name', self::PERMISSION)
            ->where('guard_name', 'web')
            ->first();

        // Revoke the grant, but never delete the permission itself — six other
        // roles are built on it.
        if ($permission !== null && $role->hasPermissionTo($permission)) {
            $role->revokePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function bankViewer(): ?Role
    {
        return Role::where('name', Roles::BANK_VIEWER)
            ->where('guard_name', 'web')
            ->first();
    }
};
