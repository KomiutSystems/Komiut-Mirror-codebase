<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Auth\Roles;
use App\Enums\UserType;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Role-based access control (App\Http\Controllers\APIs\Dashboard\Settings\RolesController).
 *
 * Covers the security boundaries the "global roles, no teams" design depends on:
 * role CRUD is superadmin-only, and member role-assignment can't cross SACCOs,
 * can't grant platform roles, and can't exceed the caller's own permissions.
 */
final class RbacTest extends QueueTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function saccoAdmin(\App\Models\Sacco $sacco): User
    {
        $user = $this->makeUser([], $sacco);
        $user->assignRole(Roles::SACCO_ADMIN);

        return $user;
    }

    private function superadmin(): User
    {
        $user = $this->makeUser();
        $user->update(['type' => UserType::Superadmin]);

        return $user;
    }

    #[Test]
    public function sacco_admin_sees_only_assignable_roles(): void
    {
        Sanctum::actingAs($this->saccoAdmin($this->makeSacco()));

        $names = collect($this->getJson('/api/auth/roles')->assertOk()->json('roles'))->pluck('name')->all();

        $this->assertContains(Roles::FLEET_MANAGER, $names);
        $this->assertNotContains(Roles::SUPER_ADMIN, $names);
    }

    #[Test]
    public function superadmin_sees_all_roles(): void
    {
        Sanctum::actingAs($this->superadmin());

        $names = collect($this->getJson('/api/auth/roles')->assertOk()->json('roles'))->pluck('name')->all();

        $this->assertContains(Roles::SUPER_ADMIN, $names);
    }

    #[Test]
    public function sacco_admin_assigns_a_role_to_its_own_member(): void
    {
        $sacco = $this->makeSacco();
        Sanctum::actingAs($this->saccoAdmin($sacco));
        $member = $this->makeUser([], $sacco);

        $this->postJson("/api/auth/saccos/members/{$member->id}/roles", ['roles' => [Roles::FLEET_MANAGER]])
            ->assertOk();

        $this->assertTrue($member->fresh()->hasRole(Roles::FLEET_MANAGER));
    }

    #[Test]
    public function sacco_admin_cannot_assign_across_saccos(): void
    {
        Sanctum::actingAs($this->saccoAdmin($this->makeSacco()));
        $foreign = $this->makeUser([], $this->makeSacco());

        $this->postJson("/api/auth/saccos/members/{$foreign->id}/roles", ['roles' => [Roles::DRIVER]])
            ->assertStatus(403);

        $this->assertFalse($foreign->fresh()->hasRole(Roles::DRIVER));
    }

    #[Test]
    public function sacco_admin_cannot_assign_a_platform_role(): void
    {
        $sacco = $this->makeSacco();
        Sanctum::actingAs($this->saccoAdmin($sacco));
        $member = $this->makeUser([], $sacco);

        $this->postJson("/api/auth/saccos/members/{$member->id}/roles", ['roles' => [Roles::SUPER_ADMIN]])
            ->assertStatus(403);

        $this->assertFalse($member->fresh()->hasRole(Roles::SUPER_ADMIN));
    }

    #[Test]
    public function a_caller_cannot_grant_beyond_their_own_permissions(): void
    {
        $sacco = $this->makeSacco();
        // Fleet Manager can edit members but lacks the full SACCO Admin permission set.
        $fleet = $this->makeUser([], $sacco);
        $fleet->assignRole(Roles::FLEET_MANAGER);
        Sanctum::actingAs($fleet);
        $member = $this->makeUser([], $sacco);

        $this->postJson("/api/auth/saccos/members/{$member->id}/roles", ['roles' => [Roles::SACCO_ADMIN]])
            ->assertStatus(403);
    }

    #[Test]
    public function role_crud_is_superadmin_only(): void
    {
        Sanctum::actingAs($this->saccoAdmin($this->makeSacco()));
        $this->postJson('/api/auth/roles/save', ['name' => 'Cashier', 'permissions' => ['View Dashboard']])
            ->assertStatus(403);

        Sanctum::actingAs($this->superadmin());
        $this->postJson('/api/auth/roles/save', ['name' => 'Cashier', 'permissions' => ['View Dashboard']])
            ->assertOk();
    }
}
