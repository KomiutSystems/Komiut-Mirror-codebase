<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Auth\Roles;
use App\Enums\UserType;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Fleet Officer: Fleet Manager's buses and crew assignments, without the
 * members screen (MetroTrans, 2026-11-26).
 */
final class FleetOfficerTest extends QueueTestCase
{
    private function officer(array $world): User
    {
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $u = $this->makeUser([], $world['sacco']);
        $u->forceFill(['type' => UserType::Admin->value])->save();
        $u->assignRole(Roles::FLEET_OFFICER, Roles::FINANCE);

        return $u->fresh();
    }

    #[Test]
    public function it_is_fleet_manager_less_the_members_permissions(): void
    {
        $bundles = Roles::bundles();
        $this->assertEqualsCanonicalizing(
            ['Add Sacco Members', 'Edit Sacco Members', 'View Sacco Members'],
            array_values(array_diff($bundles[Roles::FLEET_MANAGER], $bundles[Roles::FLEET_OFFICER])),
        );
        $this->assertSame([], array_values(array_diff($bundles[Roles::FLEET_OFFICER], $bundles[Roles::FLEET_MANAGER])));
        $this->assertContains(Roles::FLEET_OFFICER, Roles::saccoAssignable());
    }

    #[Test]
    public function the_members_screen_is_closed_to_them_and_the_fleet_is_open(): void
    {
        $world = $this->makeWorld();
        $officer = $this->officer($world);
        $driver = $this->makeUser([], $world['sacco']);
        $driver->forceFill(['type' => UserType::Driver->value])->save();
        Sanctum::actingAs($officer);

        // Members: read, add, edit, roles, crew release/delete -- all refused.
        $this->getJson('/api/v1/auth/saccos/members?sacco='.$world['sacco']->id)->assertForbidden();
        $this->postJson('/api/v1/auth/saccos/members/create', [
            'firstname' => 'A', 'lastname' => 'B', 'email' => 'a.b@example.test', 'phone' => '254700000099',
            'password' => 'password123', 'type' => 'admin',
        ])->assertForbidden();
        $this->postJson("/api/v1/auth/saccos/members/{$driver->id}/roles", ['roles' => [Roles::DRIVER]])->assertForbidden();
        $this->postJson("/api/v1/auth/crew/{$driver->id}/release")->assertForbidden();
        $this->deleteJson("/api/v1/auth/crew/{$driver->id}")->assertForbidden();

        // The fleet: buses and putting crew on them.
        $this->getJson('/api/v1/auth/crew')->assertOk();
        $this->postJson('/api/v1/auth/vehicles/users/add', ['user_id' => $driver->id, 'vehicle_id' => $world['vehicle']->id])->assertCreated();
    }
}
