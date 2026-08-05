<?php

declare(strict_types=1);

namespace Tests\Feature\Super;

use App\Enums\UserType;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Context;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The super-admin overview dashboard: a single cheap cross-brand snapshot.
 */
final class PulseTest extends QueueTestCase
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
    public function a_super_admin_reads_the_pulse_snapshot(): void
    {
        Context::add('brand', 'testing');
        $world = $this->makeWorld();

        Transaction::create([
            'vehicle_id' => $world['vehicle']->id,
            'amount' => 500,
            'trans_date' => now(),
        ]);

        Sanctum::actingAs($this->superAdmin());

        $response = $this->getJson('/api/v1/super/pulse?range=7d')->assertOk();

        $response->assertJsonStructure([
            'range', 'saccos' => ['total', 'claimed', 'pending_review', 'dormant'],
            'people' => ['users', 'drivers', 'active_today'],
            'fleet' => ['total', 'on_road_now', 'capacity_seats'],
            'money' => ['gross_volume', 'currency', 'transactions', 'failed_reconciliations', 'trend', 'change_pct'],
            'trips' => ['completed', 'bookings', 'cancelled'],
            'alerts' => ['critical', 'high', 'review'],
            'volume_series',
            'by_brand',
        ]);

        $response->assertJsonPath('range', '7d');
        $response->assertJsonPath('money.transactions', 1);
        $response->assertJsonPath('money.gross_volume', 500);
        $response->assertJsonPath('money.currency', 'KES');
        $response->assertJsonPath('saccos.total', 1);
        $response->assertJsonPath('fleet.total', 1);
    }

    #[Test]
    public function an_invalid_range_falls_back_to_seven_days(): void
    {
        Context::add('brand', 'testing');
        Sanctum::actingAs($this->superAdmin());

        $this->getJson('/api/v1/super/pulse?range=bogus')
            ->assertOk()
            ->assertJsonPath('range', '7d');
    }

    #[Test]
    public function a_non_super_admin_is_forbidden(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->getJson('/api/v1/super/pulse')->assertStatus(403);
    }
}
