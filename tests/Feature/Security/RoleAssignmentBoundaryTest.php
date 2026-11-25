<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Auth\Roles;
use App\Enums\UserType;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Two RBAC boundaries in App\Auth\Roles.
 *
 * 1. 'Bank Viewer' was listed in saccoAssignable(), so any of the 48 SACCO
 *    admins could hand it to their own staff. Once that role means "a financing
 *    bank sees the fleet it financed" it is a financier-wide view that crosses
 *    SACCO boundaries by design, which makes handing it out an escalation path
 *    out of the tenant. Granting it is superadmin-only.
 *
 * 2. The Termini-Saccos permissions have existed in permissions.csv from the
 *    start but were granted in NO bundle, so only a superadmin could hold them —
 *    which would have left the sacco_termini attach/detach endpoints unreachable
 *    for the role that already owns termini, routes and queues.
 */
final class RoleAssignmentBoundaryTest extends QueueTestCase
{
    private function role(string $name, array $permissions): Role
    {
        $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $role->syncPermissions($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $role;
    }

    #[Test]
    public function bank_viewer_is_not_a_role_a_sacco_admin_may_assign(): void
    {
        $this->assertNotContains(Roles::BANK_VIEWER, Roles::saccoAssignable());
    }

    #[Test]
    public function the_other_legacy_tiers_are_still_assignable(): void
    {
        // The removal is targeted: the three other legacy-derived roles stay,
        // because none of them reads beyond the SACCO boundary.
        $assignable = Roles::saccoAssignable();

        $this->assertContains(Roles::INVESTOR, $assignable);
        $this->assertContains(Roles::QUEUE_SUPERVISOR, $assignable);
        $this->assertContains(Roles::CASHLESS_ADMIN, $assignable);
    }

    #[Test]
    public function a_sacco_admin_is_refused_when_granting_bank_viewer(): void
    {
        $sacco = $this->makeSacco();
        // The caller holds every permission the role would grant, so a refusal
        // can only come from the assignable list — not the permission ceiling.
        $bankPermissions = ['View Summaries', 'View Transactions', 'View QRCode Payments'];
        $this->role(Roles::BANK_VIEWER, $bankPermissions);

        $admin = $this->makeUser(array_merge(['Edit Sacco Members'], $bankPermissions), $sacco);
        $member = $this->makeUser([], $sacco);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/auth/saccos/members/{$member->id}/roles", ['roles' => [Roles::BANK_VIEWER]])
            ->assertStatus(403);

        $this->assertFalse($member->fresh()->hasRole(Roles::BANK_VIEWER));
    }

    #[Test]
    public function a_sacco_admin_can_still_grant_an_ordinary_sacco_role(): void
    {
        $sacco = $this->makeSacco();
        $this->role(Roles::BOOKING_CLERK, ['View Dashboard', 'View Queues']);

        $admin = $this->makeUser(['Edit Sacco Members', 'View Dashboard', 'View Queues'], $sacco);
        $member = $this->makeUser([], $sacco);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/auth/saccos/members/{$member->id}/roles", ['roles' => [Roles::BOOKING_CLERK]])
            ->assertOk();

        $this->assertTrue($member->fresh()->hasRole(Roles::BOOKING_CLERK));
    }

    #[Test]
    public function the_operations_manager_can_link_termini_to_saccos(): void
    {
        $bundle = Roles::bundles()[Roles::OPERATIONS_MANAGER];

        $this->assertContains('View Termini Saccos', $bundle);
        $this->assertContains('Add Termini Saccos', $bundle);
        $this->assertContains('Edit Termini Saccos', $bundle);
    }

    #[Test]
    public function the_termini_saccos_permissions_are_not_platform_only(): void
    {
        // If they were, the seeder's "SACCO Admin = everything minus
        // PLATFORM_ONLY" resolution would silently withhold them again.
        foreach (['View Termini Saccos', 'Add Termini Saccos', 'Edit Termini Saccos'] as $permission) {
            $this->assertNotContains($permission, Roles::PLATFORM_ONLY);
        }
    }

    #[Test]
    public function the_permission_rows_the_bundle_references_exist_in_the_catalog(): void
    {
        // The bundle must reference the catalog verbatim — a typo would create a
        // second, unreachable permission row rather than failing loudly.
        $catalog = array_column(
            array_map('str_getcsv', file(base_path('database/data/permissions.csv'), FILE_IGNORE_NEW_LINES)),
            0
        );

        foreach (['View Termini Saccos', 'Add Termini Saccos', 'Edit Termini Saccos'] as $permission) {
            $this->assertContains($permission, $catalog);
        }
    }

    #[Test]
    public function a_member_can_hold_two_roles_and_gets_the_permissions_of_both(): void
    {
        // MetroTrans: one person runs finance and the fleet.
        $sacco = $this->makeSacco();
        $this->role(Roles::FINANCE, ['View Transactions']);
        $this->role(Roles::FLEET_MANAGER, ['View Vehicles']);
        $admin = $this->makeUser(['Edit Sacco Members', 'View Transactions', 'View Vehicles'], $sacco);
        $member = $this->makeUser([], $sacco);
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/auth/saccos/members/{$member->id}/roles", ['roles' => [Roles::FINANCE, Roles::FLEET_MANAGER]])
            ->assertOk()
            ->assertJsonPath('user.permissions', fn ($p) => in_array('View Transactions', $p) && in_array('View Vehicles', $p));

        $member = $member->fresh();
        $this->assertTrue($member->hasRole(Roles::FINANCE) && $member->hasRole(Roles::FLEET_MANAGER));

        // Unticking one removes only that one.
        $this->postJson("/api/v1/auth/saccos/members/{$member->id}/roles", ['roles' => [Roles::FLEET_MANAGER]])->assertOk();
        $this->assertSame([Roles::FLEET_MANAGER], $member->fresh()->getRoleNames()->all());
    }

    #[Test]
    public function a_sacco_admin_saving_roles_never_drops_one_a_platform_admin_gave(): void
    {
        // Bank Viewer is not on a SACCO admin's list, so they cannot tick it;
        // replacing the member's roles must not quietly take it away.
        $sacco = $this->makeSacco();
        $this->role(Roles::BANK_VIEWER, ['View Summaries']);
        $this->role(Roles::FINANCE, ['View Transactions']);
        $admin = $this->makeUser(['Edit Sacco Members', 'View Transactions', 'View Summaries'], $sacco);
        $member = $this->makeUser([], $sacco);
        $member->assignRole(Roles::BANK_VIEWER);
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/auth/saccos/members/{$member->id}/roles", ['roles' => [Roles::FINANCE]])->assertOk();

        $member = $member->fresh();
        $this->assertTrue($member->hasRole(Roles::BANK_VIEWER), 'The platform-given role must survive.');
        $this->assertTrue($member->hasRole(Roles::FINANCE));

        // Sending it back unchanged is keeping it, not granting it.
        $this->postJson("/api/v1/auth/saccos/members/{$member->id}/roles", ['roles' => [Roles::FINANCE, Roles::BANK_VIEWER]])->assertOk();
        $this->assertTrue($member->fresh()->hasRole(Roles::BANK_VIEWER));
    }

    #[Test]
    public function a_member_with_more_access_than_the_admin_cannot_be_changed_by_them(): void
    {
        // The member ends with every permission of every role they hold, so
        // the admin's ceiling covers the roles kept, not only the new one.
        $sacco = $this->makeSacco();
        $this->role(Roles::OPERATIONS_MANAGER, ['View Queues', 'Edit Queues']);
        $this->role(Roles::FINANCE, ['View Transactions']);
        $admin = $this->makeUser(['Edit Sacco Members', 'View Transactions', 'View Queues'], $sacco);
        $member = $this->makeUser([], $sacco);
        $member->assignRole(Roles::OPERATIONS_MANAGER);
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/auth/saccos/members/{$member->id}/roles", ['roles' => [Roles::OPERATIONS_MANAGER, Roles::FINANCE]])
            ->assertStatus(403);

        $this->assertSame([Roles::OPERATIONS_MANAGER], $member->fresh()->getRoleNames()->all());
    }

    #[Test]
    public function adding_a_fleet_wide_role_cannot_lift_an_investors_own_bus_limit_past_the_admin(): void
    {
        // Fleet Manager lifts an Investor's own-buses narrowing, so their money
        // permissions would read the whole SACCO -- which this Fleet Manager
        // admin cannot read themselves.
        $sacco = $this->makeSacco();
        $this->role(Roles::INVESTOR, ['View Transactions']);
        $this->role(Roles::FLEET_MANAGER, ['Edit Sacco Members', 'View Vehicles']);
        $admin = $this->makeUser([], $sacco);
        $admin->assignRole(Roles::FLEET_MANAGER);
        $member = $this->makeUser([], $sacco);
        $member->assignRole(Roles::INVESTOR);
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/auth/saccos/members/{$member->id}/roles", ['roles' => [Roles::INVESTOR, Roles::FLEET_MANAGER]])
            ->assertStatus(403);

        $this->assertSame([Roles::INVESTOR], $member->fresh()->getRoleNames()->all());
    }

    #[Test]
    public function removing_investor_cannot_widen_a_kept_role_past_the_admin(): void
    {
        // An Investor's own-buses limit covers every permission they hold, a
        // kept platform role's included. Dropping Investor would lift it.
        $sacco = $this->makeSacco();
        $this->role(Roles::INVESTOR, ['View Vehicles']);
        $this->role(Roles::BANK_VIEWER, ['View Transactions']);
        $this->role(Roles::BOOKING_CLERK, ['View Queues']);
        $admin = $this->makeUser(['Edit Sacco Members', 'View Vehicles', 'View Queues'], $sacco);
        $member = $this->makeUser([], $sacco);
        $member->assignRole(Roles::INVESTOR, Roles::BANK_VIEWER);
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/auth/saccos/members/{$member->id}/roles", ['roles' => [Roles::BOOKING_CLERK]])
            ->assertStatus(403);

        $this->assertEqualsCanonicalizing([Roles::INVESTOR, Roles::BANK_VIEWER], $member->fresh()->getRoleNames()->all());
    }

    #[Test]
    public function a_permission_given_directly_counts_toward_the_admins_ceiling(): void
    {
        $sacco = $this->makeSacco();
        $this->role(Roles::FINANCE, ['View Vehicles']);
        $admin = $this->makeUser(['Edit Sacco Members', 'View Vehicles'], $sacco);
        $member = $this->makeUser(['View Transactions'], $sacco);
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/auth/saccos/members/{$member->id}/roles", ['roles' => [Roles::FINANCE]])
            ->assertStatus(403);
    }

    #[Test]
    public function a_fleet_manager_cannot_strip_a_sacco_admin_of_their_role(): void
    {
        // The list replaces the member's roles: sending [Fleet Manager] for a
        // SACCO Admin would demote them, which this caller could never undo.
        $sacco = $this->makeSacco();
        $this->role(Roles::SACCO_ADMIN, ['Edit Sacco Members', 'View Vehicles', 'View Transactions']);
        $this->role(Roles::FLEET_MANAGER, ['Edit Sacco Members', 'View Vehicles']);
        $admin = $this->makeUser([], $sacco);
        $admin->assignRole(Roles::FLEET_MANAGER);
        $member = $this->makeUser([], $sacco);
        $member->assignRole(Roles::SACCO_ADMIN);
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/auth/saccos/members/{$member->id}/roles", ['roles' => [Roles::FLEET_MANAGER]])
            ->assertStatus(403)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'This member has access you do not have'));

        $this->assertSame([Roles::SACCO_ADMIN], $member->fresh()->getRoleNames()->all());
    }

    #[Test]
    public function an_admin_limited_to_their_own_buses_cannot_change_roles(): void
    {
        // Investor + School Admin: the permission names cover Fleet Manager,
        // but the account only reads its own buses. Adding Fleet Manager --
        // to itself or another investor -- would lift that limit.
        $sacco = $this->makeSacco();
        $this->role(Roles::INVESTOR, ['View Transactions', 'View Summaries']);
        $this->role(Roles::SCHOOL_ADMIN, ['Edit Sacco Members', 'View Vehicles']);
        $this->role(Roles::FLEET_MANAGER, ['Edit Sacco Members', 'View Vehicles', 'View Summaries']);
        $caller = $this->makeUser([], $sacco);
        $caller->assignRole(Roles::INVESTOR, Roles::SCHOOL_ADMIN);
        $other = $this->makeUser([], $sacco);
        $other->assignRole(Roles::INVESTOR);
        Sanctum::actingAs($caller);

        $this->postJson("/api/v1/auth/saccos/members/{$caller->id}/roles", ['roles' => [Roles::INVESTOR, Roles::SCHOOL_ADMIN, Roles::FLEET_MANAGER]])
            ->assertStatus(403);
        $this->postJson("/api/v1/auth/saccos/members/{$other->id}/roles", ['roles' => [Roles::INVESTOR, Roles::FLEET_MANAGER]])
            ->assertStatus(403);

        $this->assertFalse($caller->fresh()->hasRole(Roles::FLEET_MANAGER));
        $this->assertFalse($other->fresh()->hasRole(Roles::FLEET_MANAGER));
    }

    #[Test]
    public function a_sacco_admin_with_the_real_bundles_can_give_finance_and_fleet_manager(): void
    {
        // The MetroTrans case end to end, on the permissions production seeds.
        $sacco = $this->makeSacco();
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = $this->makeUser([], $sacco);
        $admin->assignRole(Roles::SACCO_ADMIN);
        $member = $this->makeUser([], $sacco);
        $member->assignRole(Roles::OPERATIONS_MANAGER);
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/auth/saccos/members/{$member->id}/roles", ['roles' => [Roles::FINANCE, Roles::FLEET_MANAGER]])
            ->assertOk();

        $this->assertEqualsCanonicalizing([Roles::FINANCE, Roles::FLEET_MANAGER], $member->fresh()->getRoleNames()->all());
    }

    #[Test]
    public function an_admin_limited_to_their_banks_buses_cannot_give_roles(): void
    {
        // A bank account reads only the buses its bank financed; a role it
        // gives would read the whole SACCO.
        $sacco = $this->makeSacco();
        $this->role(Roles::BANK_VIEWER, ['View Transactions']);
        $this->role(Roles::FINANCE, ['View Transactions']);
        $caller = $this->makeUser(['Edit Sacco Members', 'View Transactions'], $sacco);
        $caller->assignRole(Roles::BANK_VIEWER);
        $member = $this->makeUser([], $sacco);
        Sanctum::actingAs($caller);

        $this->postJson("/api/v1/auth/saccos/members/{$member->id}/roles", ['roles' => [Roles::FINANCE]])
            ->assertStatus(403)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'your bank financed'));

        $this->assertFalse($member->fresh()->hasRole(Roles::FINANCE));
    }

    #[Test]
    public function a_crew_account_holding_school_admin_is_told_why_it_cannot_give_roles(): void
    {
        // A crew-typed account sees only its own bus, School Admin or not.
        $sacco = $this->makeSacco();
        $this->role(Roles::SCHOOL_ADMIN, ['Edit Sacco Members', 'View Vehicles']);
        $this->role(Roles::DRIVER, ['View Vehicles']);
        $caller = $this->makeUser([], $sacco);
        $caller->forceFill(['type' => UserType::Driver->value])->save();
        $caller->assignRole(Roles::SCHOOL_ADMIN);
        $member = $this->makeUser([], $sacco);
        Sanctum::actingAs($caller->fresh());

        $this->postJson("/api/v1/auth/saccos/members/{$member->id}/roles", ['roles' => [Roles::DRIVER]])
            ->assertStatus(403)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'make it an office account'));
    }

    #[Test]
    public function an_admin_limited_to_their_own_buses_cannot_create_a_member_with_roles(): void
    {
        $sacco = $this->makeSacco();
        $this->role(Roles::INVESTOR, ['View Transactions', 'View Summaries']);
        $this->role(Roles::SCHOOL_ADMIN, ['Add Sacco Members', 'View Vehicles']);
        $this->role(Roles::FLEET_MANAGER, ['View Vehicles', 'View Summaries']);
        $caller = $this->makeUser([], $sacco);
        $caller->assignRole(Roles::INVESTOR, Roles::SCHOOL_ADMIN);
        Sanctum::actingAs($caller);

        $this->postJson('/api/v1/auth/saccos/members/create', [
            'firstname' => 'New', 'lastname' => 'Manager', 'email' => 'new.manager@example.test',
            'phone' => '254700999111', 'password' => 'password123', 'type' => 'admin',
            'roles' => [Roles::FLEET_MANAGER],
        ])->assertStatus(403);

        $this->assertDatabaseMissing('users', ['email' => 'new.manager@example.test']);
    }
}
