<?php

declare(strict_types=1);

namespace Tests\Feature\Super;

use App\Enums\LoyaltyTransactionType;
use App\Enums\UserType;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyTransaction;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Super-admin console — cross-brand loyalty program overview (super/loyalty).
 * `below_floor` reuses App\Services\Platform\Thresholds::get(..., 'loyalty_divisor_floor'),
 * the same floor LoyaltyProgramObserver alerts on — default 10 (config/platform.php).
 */
final class LoyaltyOverviewTest extends QueueTestCase
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
    public function below_floor_flags_correctly_against_the_threshold(): void
    {
        $healthySacco = $this->makeSacco();
        $extremeSacco = $this->makeSacco();

        // Default floor is 10 (config/platform.php: loyalty_divisor_floor).
        $healthy = LoyaltyProgram::create([
            'sacco_id' => $healthySacco->id, 'divisor' => 100, 'redemption_threshold' => 50, 'is_active' => true,
        ]);
        $extreme = LoyaltyProgram::create([
            'sacco_id' => $extremeSacco->id, 'divisor' => 5, 'redemption_threshold' => 50, 'is_active' => true,
        ]);

        Sanctum::actingAs($this->superAdmin());

        $rows = $this->getJson('/api/v1/super/loyalty')->assertOk()->json('data');

        $healthyRow = collect($rows)->firstWhere('sacco.id', $healthySacco->id);
        $extremeRow = collect($rows)->firstWhere('sacco.id', $extremeSacco->id);

        $this->assertNotNull($healthyRow);
        $this->assertNotNull($extremeRow);
        $this->assertFalse($healthyRow['below_floor'], 'divisor 100 >= floor 10 — not below floor.');
        $this->assertTrue($extremeRow['below_floor'], 'divisor 5 < floor 10 — below floor.');
        $this->assertEquals(100.0, $healthyRow['divisor']);
        $this->assertEquals(5.0, $extremeRow['divisor']);

        // The below_floor=1 filter should return only the extreme program.
        $filtered = $this->getJson('/api/v1/super/loyalty?below_floor=1')->assertOk()->json('data');
        $this->assertCount(1, $filtered);
        $this->assertSame($extremeSacco->id, $filtered[0]['sacco']['id']);
    }

    #[Test]
    public function points_issued_and_redeemed_are_summed_over_the_trailing_30_days(): void
    {
        $sacco = $this->makeSacco();
        $user = $this->makeUser([], $sacco);
        $program = LoyaltyProgram::create([
            'sacco_id' => $sacco->id, 'divisor' => 100, 'redemption_threshold' => 20, 'is_active' => true,
        ]);

        LoyaltyTransaction::create([
            'user_id' => $user->id, 'sacco_id' => $sacco->id, 'value' => 5.0,
            'type' => LoyaltyTransactionType::Earned->value,
        ]);
        LoyaltyTransaction::create([
            'user_id' => $user->id, 'sacco_id' => $sacco->id, 'value' => 3.0,
            'type' => LoyaltyTransactionType::Earned->value,
        ]);
        LoyaltyTransaction::create([
            'user_id' => $user->id, 'sacco_id' => $sacco->id, 'value' => -4.0,
            'type' => LoyaltyTransactionType::Redeemed->value,
        ]);

        // Outside the 30-day window — must not count.
        $old = LoyaltyTransaction::create([
            'user_id' => $user->id, 'sacco_id' => $sacco->id, 'value' => 99.0,
            'type' => LoyaltyTransactionType::Earned->value,
        ]);
        $old->forceFill(['created_at' => now()->subDays(45)])->save();

        Sanctum::actingAs($this->superAdmin());

        $rows = $this->getJson('/api/v1/super/loyalty?sacco_id='.$sacco->id)->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertEquals(8.0, $rows[0]['points_issued_30d']);
        $this->assertEquals(4.0, $rows[0]['points_redeemed_30d'], 'Redeemed total is reported as a positive magnitude.');
        $this->assertSame($program->updated_at?->toIso8601String(), $rows[0]['updated_at']);
    }

    #[Test]
    public function loyalty_requires_the_permission(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['type' => UserType::Superadmin])->save();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/super/loyalty')->assertStatus(403);
    }
}
