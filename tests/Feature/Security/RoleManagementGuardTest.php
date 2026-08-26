<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Auth\Roles;
use App\Enums\UserType;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Role management is platform-only, and must be unreachable without it.
 *
 * POST users/roles/add had no route middleware and no in-method permission check
 * — unlike every sibling write on the same controller — so any authenticated
 * caller could reach it. Creating a spare role is untidy; RENAMING one is
 * privilege escalation, because Spatie matches roles by name. Rename a SACCO
 * role to "Super Admin" and everyone already holding it owns the platform,
 * without a single permission being granted.
 */
final class RoleManagementGuardTest extends QueueTestCase
{
    private function userOfType(UserType $type): User
    {
        $user = $this->makeUser([], null);
        $user->type = $type;
        $user->save();

        return $user->fresh();
    }

    #[Test]
    public function a_passenger_cannot_create_a_role(): void
    {
        Sanctum::actingAs($this->userOfType(UserType::Passenger));

        $this->postJson('/api/v1/auth/users/roles/add', ['id' => 0, 'name' => 'Totally Legit'])
            ->assertStatus(403);

        $this->assertDatabaseMissing('roles', ['name' => 'Totally Legit']);
    }

    #[Test]
    public function a_driver_cannot_rename_an_existing_role_into_a_platform_role(): void
    {
        // The escalation: roles are matched by name, so renaming a role you
        // already hold to the platform role's name grants you the platform.
        $victim = Role::firstOrCreate(['name' => 'Booking Clerk', 'guard_name' => 'web']);

        Sanctum::actingAs($this->userOfType(UserType::Driver));

        $this->postJson('/api/v1/auth/users/roles/add', ['id' => $victim->id, 'name' => Roles::SUPER_ADMIN])
            ->assertStatus(403);

        $this->assertSame('Booking Clerk', $victim->fresh()->name);
    }

    #[Test]
    public function a_sacco_admin_cannot_manage_roles_either(): void
    {
        // Add/Edit Roles are PLATFORM_ONLY — a SACCO Admin holds everything else,
        // but not these.
        $admin = $this->userOfType(UserType::Admin);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/auth/users/roles/add', ['id' => 0, 'name' => 'Sneaky Role'])
            ->assertStatus(403);

        $this->assertDatabaseMissing('roles', ['name' => 'Sneaky Role']);
    }

    #[Test]
    public function a_holder_of_the_permission_can_still_create_a_role(): void
    {
        // The guard must not break the people it is for.
        $admin = $this->makeUser(['Add Roles', 'Edit Roles'], null);
        $admin->type = UserType::Superadmin;
        $admin->save();

        Sanctum::actingAs($admin->fresh());

        $this->postJson('/api/v1/auth/users/roles/add', ['id' => 0, 'name' => 'Night Supervisor'])
            ->assertOk();

        $this->assertDatabaseHas('roles', ['name' => 'Night Supervisor']);
    }

    #[Test]
    public function an_unknown_role_id_is_a_404_not_a_500(): void
    {
        $admin = $this->makeUser(['Add Roles', 'Edit Roles'], null);
        $admin->type = UserType::Superadmin;
        $admin->save();

        Sanctum::actingAs($admin->fresh());

        $this->postJson('/api/v1/auth/users/roles/add', ['id' => 999999, 'name' => 'Ghost'])
            ->assertStatus(404);
    }
}
