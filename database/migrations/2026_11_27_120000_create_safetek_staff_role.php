<?php

declare(strict_types=1);

use App\Auth\Roles;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The Safetek Staff role and its four console permissions, made here because
 * deploy never seeds. Super Admin gets the permissions too (it holds every
 * permission), so the routes that now accept either keep working for it.
 * Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $names = Roles::bundles()[Roles::SAFETEK_STAFF];
        $permissions = collect($names)->map(fn (string $n) => Permission::findOrCreate($n, 'web'));

        Role::findOrCreate(Roles::SAFETEK_STAFF, 'web')->syncPermissions($permissions);

        if ($super = Role::where('name', Roles::SUPER_ADMIN)->where('guard_name', 'web')->first()) {
            $super->givePermissionTo($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::where('name', Roles::SAFETEK_STAFF)->where('guard_name', 'web')->delete();
        Permission::whereIn('name', Roles::bundles()[Roles::SAFETEK_STAFF])->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
