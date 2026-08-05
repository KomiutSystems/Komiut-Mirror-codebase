<?php

declare(strict_types=1);

namespace Tests\Feature\Super;

use App\Enums\UserType;
use App\Models\AuditLog;
use App\Models\PlatformNotification;
use App\Models\User;
use Illuminate\Support\Facades\Context;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * RBAC reads (brands/roles/permissions) + the role->permissions mutation,
 * which must reuse AccessChangeRecorder::recordPermissionSync (the same path
 * RolesController::saveRole already calls) rather than emitting a second time.
 */
final class RbacTest extends QueueTestCase
{
    private function superAdmin(): User
    {
        $user = $this->makeUser();
        $user->forceFill(['type' => UserType::Superadmin])->save();
        Permission::findOrCreate('View Platform Notifications', 'web');
        $user->givePermissionTo('View Platform Notifications');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    #[Test]
    public function the_brands_endpoint_lists_the_configured_catalog(): void
    {
        Context::add('brand', 'testing');
        $this->makeSacco();
        Sanctum::actingAs($this->superAdmin());

        $response = $this->getJson('/api/v1/super/brands')->assertOk();

        $response->assertJsonStructure([
            '*' => ['brand', 'name', 'hosts', 'app_key_set', 'saccos', 'users', 'tls_expires_at', 'bank_partner', 'status'],
        ]);
        $response->assertJsonPath('0.brand', 'testing');
    }

    #[Test]
    public function the_roles_endpoint_lists_roles_with_permissions(): void
    {
        Context::add('brand', 'testing');
        Role::findOrCreate('SACCO Admin', 'web');
        Permission::findOrCreate('View Vehicles', 'web');
        Role::findOrCreate('SACCO Admin', 'web')->givePermissionTo('View Vehicles');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($this->superAdmin());

        $this->getJson('/api/v1/super/roles')
            ->assertOk()
            ->assertJsonFragment(['name' => 'SACCO Admin'])
            ->assertJsonFragment(['name' => 'View Vehicles', 'group' => null]);
    }

    #[Test]
    public function the_permissions_endpoint_lists_every_permission(): void
    {
        Context::add('brand', 'testing');
        Permission::findOrCreate('View Vehicles', 'web');
        Sanctum::actingAs($this->superAdmin());

        $this->getJson('/api/v1/super/permissions')
            ->assertOk()
            ->assertJsonFragment(['name' => 'View Vehicles']);
    }

    #[Test]
    public function updating_role_permissions_audits_and_emits_a_critical_alert(): void
    {
        Context::add('brand', 'testing');
        $role = Role::findOrCreate('SACCO Admin', 'web');
        Permission::findOrCreate('View Vehicles', 'web');
        Permission::findOrCreate('Edit Vehicles', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($this->superAdmin());

        $this->putJson("/api/v1/super/roles/{$role->id}/permissions", [
            'permissions' => ['View Vehicles', 'Edit Vehicles'],
        ])
            ->assertOk()
            ->assertJsonPath('role.name', 'SACCO Admin');

        $audit = AuditLog::where('action', 'access.role.permissions_changed')->first();
        $this->assertNotNull($audit, 'The mutation must go through AccessChangeRecorder, which audits first.');
        $this->assertEqualsCanonicalizing(['View Vehicles', 'Edit Vehicles'], $audit->data['added']);

        $notification = PlatformNotification::where('event', 'access.role.permissions_changed')->first();
        $this->assertNotNull($notification);
        $this->assertSame('critical', $notification->severity);
        $this->assertSame('alert', $notification->delivery_class);
        $this->assertSame($audit->id, $notification->audit_id);

        // Exactly one audit/notification row — proof this route did not emit a
        // second, separate copy of the event alongside the recorder's own.
        $this->assertSame(1, AuditLog::where('action', 'access.role.permissions_changed')->count());
        $this->assertSame(1, PlatformNotification::where('event', 'access.role.permissions_changed')->count());
    }

    #[Test]
    public function a_non_super_admin_is_forbidden(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->getJson('/api/v1/super/roles')->assertStatus(403);
    }
}
