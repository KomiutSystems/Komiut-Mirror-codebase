<?php

declare(strict_types=1);

namespace Tests\Feature\Super;

use App\Enums\UserType;
use App\Models\AuditLog;
use App\Models\PlatformNotification;
use App\Models\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The super-admin user directory: list/search/filter + the suspend/restore/
 * password-reset/delete admin actions.
 */
final class UsersTest extends QueueTestCase
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
    public function the_user_list_uses_the_slim_page_envelope_plus_a_summary(): void
    {
        Context::add('brand', 'testing');
        $this->makeUser();
        $this->makeUser();
        Sanctum::actingAs($this->superAdmin());

        $response = $this->getJson('/api/v1/super/users')->assertOk();

        $response->assertJsonStructure([
            'data', 'total', 'per_page', 'current_page', 'last_page',
            'summary' => ['total', 'active', 'suspended', 'privileged'],
        ]);
    }

    #[Test]
    public function searching_by_q_matches_name_email_and_phone(): void
    {
        Context::add('brand', 'testing');
        $target = $this->makeUser();
        $target->forceFill(['firstname' => 'Zawadi', 'email' => 'zawadi@example.test'])->save();
        $this->makeUser();

        Sanctum::actingAs($this->superAdmin());

        $this->getJson('/api/v1/super/users?q=Zawadi')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $target->id);
    }

    #[Test]
    public function the_status_filter_respects_the_suspension_override(): void
    {
        Context::add('brand', 'testing');
        // The super admin caller is created FIRST (lowest id) and is itself an
        // active user (status=true, no suspension) — orderByDesc('id') then
        // puts $active ahead of it in the "active" bucket, and the bucket's
        // total legitimately counts both.
        Sanctum::actingAs($this->superAdmin());

        $active = $this->makeUser();
        $inactive = $this->makeUser(status: false);
        $suspended = $this->makeUser();
        $suspended->forceFill(['suspended_at' => now(), 'suspension_reason' => 'fraud'])->save();

        $this->getJson('/api/v1/super/users?status=active')
            ->assertOk()->assertJsonPath('data.0.id', $active->id)->assertJsonPath('total', 2);

        $this->getJson('/api/v1/super/users?status=inactive')
            ->assertOk()->assertJsonPath('data.0.id', $inactive->id)->assertJsonPath('total', 1);

        $this->getJson('/api/v1/super/users?status=suspended')
            ->assertOk()->assertJsonPath('data.0.id', $suspended->id)->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.status', 'suspended');
    }

    #[Test]
    public function suspending_an_admin_audits_and_notifies(): void
    {
        Context::add('brand', 'testing');
        $target = $this->makeUser();
        Role::findOrCreate('SACCO Admin', 'web');
        $target->assignRole('SACCO Admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($this->superAdmin());

        $this->postJson("/api/v1/super/users/{$target->id}/suspend", ['reason' => 'suspicious activity'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.status', 'suspended')
            ->assertJsonPath('user.suspension_reason', 'suspicious activity');

        $this->assertSame(1, AuditLog::where('action', 'access.user.suspended')->count());

        $notification = PlatformNotification::where('event', 'access.user.suspended')->first();
        $this->assertNotNull($notification, 'An admin suspension must emit a platform event.');
        $this->assertSame('high', $notification->severity);
        $this->assertNotNull($notification->audit_id);
    }

    #[Test]
    public function suspending_a_non_admin_audits_but_does_not_notify(): void
    {
        Context::add('brand', 'testing');
        $target = $this->makeUser();
        Sanctum::actingAs($this->superAdmin());

        $this->postJson("/api/v1/super/users/{$target->id}/suspend", ['reason' => 'test'])->assertOk();

        $this->assertSame(1, AuditLog::where('action', 'access.user.suspended')->count());
        $this->assertSame(0, PlatformNotification::where('event', 'access.user.suspended')->count());
    }

    #[Test]
    public function restoring_clears_the_suspension_and_audits(): void
    {
        Context::add('brand', 'testing');
        $target = $this->makeUser();
        $target->forceFill(['suspended_at' => now(), 'suspension_reason' => 'x'])->save();

        Sanctum::actingAs($this->superAdmin());

        $this->postJson("/api/v1/super/users/{$target->id}/restore")
            ->assertOk()
            ->assertJsonPath('user.status', 'active')
            ->assertJsonPath('user.suspended_at', null);

        $this->assertSame(1, AuditLog::where('action', 'access.user.restored')->count());
    }

    #[Test]
    public function password_reset_returns_a_temporary_password_and_audits_without_it(): void
    {
        Context::add('brand', 'testing');
        $target = $this->makeUser();
        Sanctum::actingAs($this->superAdmin());

        $response = $this->postJson("/api/v1/super/users/{$target->id}/password-reset")
            ->assertOk()
            ->assertJsonPath('success', true);

        $tempPassword = $response->json('temporary_password');
        $this->assertIsString($tempPassword);
        $this->assertGreaterThanOrEqual(16, strlen($tempPassword));

        $audit = AuditLog::where('action', 'access.user.password_reset_by_admin')->first();
        $this->assertNotNull($audit);
        $this->assertArrayNotHasKey('password', $audit->data);
        $this->assertSame(['targetUserId' => $target->id], $audit->data);

        $target->refresh();
        $this->assertTrue(Hash::check($tempPassword, $target->password));
    }

    #[Test]
    public function deleting_a_user_is_soft_and_audits(): void
    {
        Context::add('brand', 'testing');
        $target = $this->makeUser();
        Sanctum::actingAs($this->superAdmin());

        $this->deleteJson("/api/v1/super/users/{$target->id}", ['reason' => 'closed account'])
            ->assertOk()
            ->assertJsonPath('user.status', 'suspended');

        $this->assertDatabaseHas('users', ['id' => $target->id]);
        $this->assertSame(1, AuditLog::where('action', 'access.user.deleted')->count());
    }

    #[Test]
    public function a_super_admin_cannot_be_deleted_this_way(): void
    {
        Context::add('brand', 'testing');
        $target = $this->superAdmin();
        Sanctum::actingAs($this->superAdmin());

        $this->deleteJson("/api/v1/super/users/{$target->id}", ['reason' => 'x'])
            ->assertStatus(422);
    }

    #[Test]
    public function a_non_super_admin_is_forbidden(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->getJson('/api/v1/super/users')->assertStatus(403);
    }
}
