<?php

declare(strict_types=1);

namespace Tests\Feature\Super;

use App\Enums\UserType;
use App\Models\PlatformNotification;
use App\Models\User;
use App\Services\Platform\PlatformEvent;
use App\Services\Platform\PlatformNotifier;
use Illuminate\Support\Facades\Context;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The super-admin console backbone: cross-brand feed, super-admin gate, and the
 * dedup/throttle in PlatformNotifier. Domain emitters are tested by their own
 * agents' suites.
 */
final class PlatformNotificationsTest extends QueueTestCase
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

    private function emit(string $brand): PlatformNotification
    {
        return app(PlatformNotifier::class)->dispatch(new PlatformEvent(
            event: 'vehicle.payment_details.changed',
            severity: 'critical',
            class: 'alert',
            title: 'Till changed',
            summary: 'A vehicle till was changed',
            brand: $brand,
        ));
    }

    #[Test]
    public function a_super_admin_reads_the_cross_brand_feed(): void
    {
        Context::add('brand', 'komiut');
        $this->emit('safiri'); // an event for a DIFFERENT brand

        Sanctum::actingAs($this->superAdmin());

        $this->getJson('/api/v1/super/notifications')
            ->assertOk()
            ->assertJsonPath('message.items.0.event', 'vehicle.payment_details.changed')
            ->assertJsonPath('message.items.0.brand', 'safiri') // cross-brand visible
            ->assertJsonPath('message.unreadCount', 1);
    }

    #[Test]
    public function a_non_super_admin_is_forbidden(): void
    {
        $this->emit('komiut');
        Sanctum::actingAs($this->makeUser()); // ordinary user

        $this->getJson('/api/v1/super/notifications')->assertStatus(403);
    }

    #[Test]
    public function marking_read_drops_the_unread_count(): void
    {
        $n = $this->emit('komiut');
        Sanctum::actingAs($this->superAdmin());

        $this->postJson("/api/v1/super/notifications/{$n->id}/read")->assertOk();
        $this->getJson('/api/v1/super/notifications/unread-count')
            ->assertOk()->assertJsonPath('message.count', 0);
    }

    #[Test]
    public function the_notifier_dedups_within_the_window(): void
    {
        $notifier = app(PlatformNotifier::class);
        $fire = fn () => $notifier->dispatch(new PlatformEvent(
            event: 'driver.login.failed_burst', severity: 'critical', class: 'alert',
            title: 'Login burst', summary: 'repeated failed logins',
            dedupeKey: 'burst:KDA001A', windowMinutes: 15,
        ));

        $fire();
        $fire();
        $fire();

        $this->assertSame(1, PlatformNotification::count(), 'One row, not three.');
        $this->assertSame(3, (int) PlatformNotification::first()->count);
    }

    #[Test]
    public function audit_critical_events_are_never_throttled(): void
    {
        $notifier = app(PlatformNotifier::class);
        // windowMinutes = 0 (default) → each occurrence is its own discrete row.
        foreach (range(1, 3) as $i) {
            $notifier->dispatch(new PlatformEvent(
                event: 'access.super_admin.changed', severity: 'critical', class: 'alert',
                title: 'Role changed', summary: 'super admin granted',
                dedupeKey: 'sa:5', windowMinutes: 0,
            ));
        }

        $this->assertSame(3, PlatformNotification::count(), 'Audit-critical events must not collapse.');
    }
}
