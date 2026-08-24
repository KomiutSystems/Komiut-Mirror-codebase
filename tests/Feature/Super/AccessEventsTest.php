<?php

declare(strict_types=1);

namespace Tests\Feature\Super;

use App\Enums\UserType;
use App\Models\AuditLog;
use App\Models\PlatformNotification;
use App\Models\User;
use App\Services\Super\Access\AccessChangeRecorder;
use Illuminate\Support\Facades\Context;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The ACCESS/PRIVILEGE domain emitters + the audit read endpoint.
 */
final class AccessEventsTest extends QueueTestCase
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
    public function granting_super_admin_emits_the_event_with_an_audit_row(): void
    {
        Context::add('brand', 'testing');

        $actor = $this->makeUser();
        $target = $this->makeUser();

        $rolesBefore = $target->getRoleNames()->all();
        $permsBefore = $target->getAllPermissions()->pluck('name')->all();

        Role::findOrCreate('Super Admin', 'web');
        $target->assignRole('Super Admin');

        app(AccessChangeRecorder::class)->recordRoleSync($target, $rolesBefore, $permsBefore, $actor);

        $notification = PlatformNotification::where('event', 'access.super_admin.changed')->first();
        $this->assertNotNull($notification, 'access.super_admin.changed should be emitted.');
        $this->assertSame('granted', $notification->data['action']);
        $this->assertSame($target->id, $notification->data['targetUserId']);
        $this->assertSame($actor->id, $notification->data['changedBy']);
        $this->assertNotNull($notification->audit_id, 'The event must reference its audit row.');

        $this->assertSame(1, AuditLog::where('action', 'access.super_admin.changed')->count());
    }

    #[Test]
    public function a_login_failure_burst_on_an_admin_account_emits(): void
    {
        Context::add('brand', 'testing');

        $admin = $this->makeUser();
        Role::findOrCreate('SACCO Admin', 'web');
        $admin->assignRole('SACCO Admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $recorder = app(AccessChangeRecorder::class);
        foreach (range(1, 5) as $i) {
            $recorder->recordFailedLogin($admin->email, '10.0.0.'.$i);
        }

        $burst = PlatformNotification::where('event', 'access.login.failed_burst')->get();
        $this->assertCount(1, $burst, 'Exactly one alert at the threshold crossing.');
        $this->assertSame(5, $burst->first()->data['attemptCount']);
        $this->assertSame($admin->id, $burst->first()->data['userId']);
        $this->assertCount(5, $burst->first()->data['sourceIps']);
    }

    #[Test]
    public function a_non_admin_login_failure_burst_is_ignored(): void
    {
        Context::add('brand', 'testing');

        $plain = $this->makeUser(); // no admin role, no admin type
        $recorder = app(AccessChangeRecorder::class);
        foreach (range(1, 8) as $i) {
            $recorder->recordFailedLogin($plain->email, '10.0.0.'.$i);
        }

        $this->assertSame(0, PlatformNotification::where('event', 'access.login.failed_burst')->count());
    }

    #[Test]
    public function the_access_audit_endpoint_returns_rows(): void
    {
        Context::add('brand', 'testing');

        $target = $this->makeUser();
        Role::findOrCreate('Super Admin', 'web');
        $target->assignRole('Super Admin');
        app(AccessChangeRecorder::class)->recordRoleSync($target, [], [], null);

        Sanctum::actingAs($this->superAdmin());

        $this->getJson('/api/v1/super/access/audit')
            ->assertOk()
            ->assertJsonPath('message.items.0.action', 'access.super_admin.changed')
            ->assertJsonPath('message.totalCount', 1);
    }

    #[Test]
    public function the_access_audit_endpoint_requires_the_permission(): void
    {
        // A super admin WITHOUT `View Platform Notifications` clears the `super`
        // middleware but is still rejected by the route's permission gate.
        $user = $this->makeUser();
        $user->forceFill(['type' => UserType::Superadmin])->save();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/super/access/audit')->assertStatus(403);
    }

    #[Test]
    public function the_login_endpoint_still_401s_and_emits_a_burst_for_an_admin(): void
    {
        $admin = $this->makeUser();
        $admin->forceFill(['email' => 'burst-admin@example.test'])->save();
        Role::findOrCreate('SACCO Admin', 'web');
        $admin->assignRole('SACCO Admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (range(1, 5) as $i) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'burst-admin@example.test',
                'password' => 'wrong-password',
            ])
                ->assertStatus(401)
                ->assertJsonPath('error', "We couldn't sign you in. Check your email or phone number and password, then try again.");
        }

        $burst = PlatformNotification::where('event', 'access.login.failed_burst')->get();
        $this->assertCount(1, $burst, 'The login hook must fire exactly one burst alert.');
        $this->assertSame(5, $burst->first()->data['attemptCount']);
        $this->assertSame($admin->id, $burst->first()->data['userId']);
    }
}
