<?php

declare(strict_types=1);

use App\Auth\Roles;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * A permission of its own for moving float money.
 *
 * WHY NOT REUSE AN EXISTING ONE. The wallet's read endpoints sit behind
 * `View Platform Notifications`, which is right -- looking at the float is
 * something any platform money role needs. But freezing a passenger's wallet,
 * correcting a balance, refunding a fare and marking a SACCO paid all move or
 * restrict real money held on somebody else's behalf. Gating those behind the
 * same permission would mean everyone who can READ the dashboard can also
 * adjust a stranger's balance.
 *
 * There was no existing write permission to borrow: the platform console has
 * `View Platform Notifications`, `View Platform Logs` and
 * `Manage Platform Thresholds` and nothing else. Naming a permission that does
 * not exist in a route is worse than reusing the wrong one -- Spatie would
 * simply never match it and every one of these endpoints would 403 for
 * everybody, which reads as a broken feature rather than a missing grant.
 *
 * PLATFORM_ONLY, so Roles::saccoAssignable() can never hand it to a SACCO role:
 * the float is Komiut's liability across every SACCO, and one operator being
 * able to adjust another operator's passengers' balances is not a boundary to
 * leave to convention.
 *
 * GRANTED TO SUPER ADMIN ONLY. RoleSeeder gives SUPER_ADMIN the whole
 * catalogue, so a fresh install picks this up anyway; this migration is what
 * gets it onto an environment that already exists. A role that is absent is
 * SKIPPED rather than created -- conjuring one here would produce a role
 * carrying this single permission and nothing else.
 */
return new class extends Migration
{
    private const PERMISSION = 'Manage Wallet Float';

    private const GUARD = 'web';

    /** @var array<int, string> */
    private const GRANTED_TO = [Roles::SUPER_ADMIN];

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

        $permission->delete();

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
