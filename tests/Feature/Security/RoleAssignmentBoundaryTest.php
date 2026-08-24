<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Auth\Roles;
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
}
