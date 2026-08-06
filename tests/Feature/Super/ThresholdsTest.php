<?php

declare(strict_types=1);

namespace Tests\Feature\Super;

use App\Enums\UserType;
use App\Models\AuditLog;
use App\Models\PlatformThreshold;
use App\Models\User;
use App\Services\Platform\Thresholds;
use Illuminate\Support\Facades\Context;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Retuning an alert threshold is a security-relevant act — set
 * driver_login_burst high enough and a real credential-stuffing alert never
 * fires — so these cover the guard rails as much as the happy path.
 */
final class ThresholdsTest extends QueueTestCase
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

    protected function tearDown(): void
    {
        Thresholds::bust(null);
        Thresholds::bust('testing');
        Context::flush();
        parent::tearDown();
    }

    #[Test]
    public function it_returns_resolved_thresholds_with_the_shipped_defaults(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $response = $this->getJson('/api/v1/super/thresholds')->assertOk();

        $response->assertJsonStructure(['brand', 'thresholds', 'defaults', 'overridden']);
        $this->assertSame(14, $response->json('thresholds.sacco_dormant_days'));
        $this->assertSame([], $response->json('overridden'), 'Nothing is overridden before a write.');
    }

    #[Test]
    public function a_scalar_override_persists_and_resolves(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->putJson('/api/v1/super/thresholds', ['thresholds' => ['sacco_dormant_days' => 30]])
            ->assertOk()
            ->assertJsonPath('thresholds.sacco_dormant_days', 30)
            ->assertJsonPath('changed.sacco_dormant_days.from', 14)
            ->assertJsonPath('changed.sacco_dormant_days.to', 30);

        $this->assertDatabaseHas('platform_thresholds', ['brand' => null, 'key' => 'sacco_dormant_days']);

        Thresholds::bust(null);
        $this->assertSame(30, Thresholds::get(null, 'sacco_dormant_days'));
    }

    #[Test]
    public function a_partial_shape_override_keeps_the_untouched_field(): void
    {
        // The trap: overriding `count` alone must not blank `window_minutes`,
        // which would leave the detector with no window at all.
        Sanctum::actingAs($this->superAdmin());

        $this->putJson('/api/v1/super/thresholds', [
            'thresholds' => ['driver_login_burst' => ['count' => 9]],
        ])->assertOk();

        Thresholds::bust(null);
        $resolved = Thresholds::get(null, 'driver_login_burst');

        $this->assertSame(9, $resolved['count']);
        $this->assertSame(15, $resolved['window_minutes'], 'The shipped window must survive a partial override.');
    }

    #[Test]
    public function null_removes_an_override_and_restores_the_default(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->putJson('/api/v1/super/thresholds', ['thresholds' => ['sacco_dormant_days' => 30]])->assertOk();
        $this->assertSame(1, PlatformThreshold::count());

        $this->putJson('/api/v1/super/thresholds', ['thresholds' => ['sacco_dormant_days' => null]])
            ->assertOk()
            ->assertJsonPath('thresholds.sacco_dormant_days', 14);

        $this->assertSame(0, PlatformThreshold::count(), 'Reset must delete the row, not store a null.');
    }

    #[Test]
    public function a_brand_override_beats_the_platform_wide_one(): void
    {
        Sanctum::actingAs($this->superAdmin());

        // 'testing' is the only brand the suite registers (see tests/TestCase.php).
        $this->putJson('/api/v1/super/thresholds', ['thresholds' => ['sacco_dormant_days' => 30]])->assertOk();
        $this->putJson('/api/v1/super/thresholds', ['brand' => 'testing', 'thresholds' => ['sacco_dormant_days' => 45]])->assertOk();

        Thresholds::bust(null);
        Thresholds::bust('testing');

        $this->assertSame(45, Thresholds::get('testing', 'sacco_dormant_days'));
        $this->assertSame(30, Thresholds::get(null, 'sacco_dormant_days'), 'The platform layer is unchanged.');
    }

    #[Test]
    public function an_unknown_key_is_rejected(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->putJson('/api/v1/super/thresholds', ['thresholds' => ['not_a_threshold' => 5]])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'known']);

        $this->assertSame(0, PlatformThreshold::count());
    }

    #[Test]
    public function a_wrong_shape_is_rejected(): void
    {
        Sanctum::actingAs($this->superAdmin());

        // Scalar where a shape is expected — would disable the window silently.
        $this->putJson('/api/v1/super/thresholds', ['thresholds' => ['driver_login_burst' => 9]])
            ->assertStatus(422);

        // Shape where a scalar is expected.
        $this->putJson('/api/v1/super/thresholds', ['thresholds' => ['sacco_dormant_days' => ['count' => 3]]])
            ->assertStatus(422);

        // Unknown field inside a shaped threshold.
        $this->putJson('/api/v1/super/thresholds', ['thresholds' => ['driver_login_burst' => ['nope' => 1]]])
            ->assertStatus(422);

        // Non-numeric value.
        $this->putJson('/api/v1/super/thresholds', ['thresholds' => ['sacco_dormant_days' => 'soon']])
            ->assertStatus(422);

        $this->assertSame(0, PlatformThreshold::count());
    }

    #[Test]
    public function an_unknown_brand_is_rejected(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->putJson('/api/v1/super/thresholds', ['brand' => 'nope', 'thresholds' => ['sacco_dormant_days' => 30]])
            ->assertStatus(422);

        $this->getJson('/api/v1/super/thresholds?brand=nope')->assertStatus(422);
    }

    #[Test]
    public function a_change_is_audited_with_before_and_after(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->putJson('/api/v1/super/thresholds', ['thresholds' => ['sacco_dormant_days' => 30]])->assertOk();

        $audit = AuditLog::where('action', 'platform.thresholds.changed')->first();
        $this->assertNotNull($audit, 'Retuning a detector must be auditable.');
        $this->assertSame('platform', $audit->data['scope']);
        $this->assertSame(14, $audit->data['changed']['sacco_dormant_days']['from']);
        $this->assertSame(30, $audit->data['changed']['sacco_dormant_days']['to']);
    }

    #[Test]
    public function a_no_op_write_is_not_audited(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->putJson('/api/v1/super/thresholds', ['thresholds' => ['sacco_dormant_days' => 14]])
            ->assertOk()
            ->assertJsonPath('changed', []);

        $this->assertSame(0, AuditLog::where('action', 'platform.thresholds.changed')->count());
    }

    #[Test]
    public function a_non_super_admin_is_forbidden(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->getJson('/api/v1/super/thresholds')->assertStatus(403);
        $this->putJson('/api/v1/super/thresholds', ['thresholds' => ['sacco_dormant_days' => 30]])->assertStatus(403);
    }
}
