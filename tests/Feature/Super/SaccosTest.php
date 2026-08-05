<?php

declare(strict_types=1);

namespace Tests\Feature\Super;

use App\Enums\SaccoClaimStatus;
use App\Enums\UserType;
use App\Models\LoyaltyProgram;
use App\Models\Sacco;
use App\Models\SaccoUser;
use App\Models\Transaction;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The SACCO list + detail routes (PRIORITY-1) for the super-admin console.
 * `members` reads the real SaccoUser membership table (see
 * App\Http\Controllers\APIs\Dashboard\Saccos\SaccoMembersAPIController), not a
 * raw sacco_id count — QueueTestCase::makeUser()/makeVehicle() never populate
 * either the membership row or the `type` column, so every count assertion here
 * builds its own fixture explicitly rather than assuming makeWorld() alone
 * produces a non-zero count.
 */
final class SaccosTest extends QueueTestCase
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
    public function the_list_row_reports_accurate_counts(): void
    {
        $world = $this->makeWorld(); // sacco + 1 vehicle + 1 SaccoRoute + an owner user
        $world['owner']->forceFill(['type' => UserType::Driver])->save();
        SaccoUser::create([
            'user_id' => $world['owner']->id,
            'sacco_id' => $world['sacco']->id,
            'start_date' => now(),
            'status' => 1,
            'created_by' => $world['owner']->id,
        ]);

        Sanctum::actingAs($this->superAdmin());

        $row = $this->getJson('/api/v1/super/saccos?brand=testing')
            ->assertOk()
            ->json('data.0');

        $this->assertSame($world['sacco']->id, $row['id']);
        $this->assertSame(1, $row['members'], 'members must read SaccoUser, not a raw sacco_id count.');
        $this->assertSame(1, $row['drivers'], 'drivers must be type-filtered.');
        $this->assertSame(1, $row['vehicles']);
        $this->assertSame(1, $row['routes']);
    }

    #[Test]
    public function filters_narrow_the_list(): void
    {
        $this->makeSacco(); // brand=testing, claim_status defaults to directory
        $claimed = Sacco::create([
            'name' => 'Zed Sacco',
            'status' => 1,
            'brand' => 'other-brand',
            'claim_status' => SaccoClaimStatus::Claimed,
        ]);

        Sanctum::actingAs($this->superAdmin());

        $this->getJson('/api/v1/super/saccos?brand=other-brand')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $claimed->id);

        $this->getJson('/api/v1/super/saccos?claim_status=claimed')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $claimed->id);

        $this->getJson('/api/v1/super/saccos?'.http_build_query(['q' => 'Zed']))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $claimed->id);
    }

    #[Test]
    public function unknown_sacco_detail_is_a_404(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->getJson('/api/v1/super/saccos/999999')->assertNotFound();
    }

    #[Test]
    public function the_detail_endpoint_returns_counts_money_loyalty_and_recent_events(): void
    {
        $world = $this->makeWorld();
        $sacco = $world['sacco'];
        $world['owner']->forceFill(['type' => UserType::Driver])->save();
        SaccoUser::create([
            'user_id' => $world['owner']->id,
            'sacco_id' => $sacco->id,
            'start_date' => now(),
            'status' => 1,
            'created_by' => $world['owner']->id,
        ]);

        LoyaltyProgram::create([
            'sacco_id' => $sacco->id,
            'divisor' => 100,
            'redemption_threshold' => 500,
            'is_active' => true,
        ]);

        Transaction::create([
            'vehicle_id' => $world['vehicle']->id,
            'amount' => 500,
            'trans_date' => now(),
            'summarized' => false,
        ]);

        $queueStatus = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $queueStatus, $world['owner']);
        $this->makeBooking($queue, $world['owner'], $world['from'], $world['to']);

        Sanctum::actingAs($this->superAdmin());

        $data = $this->getJson("/api/v1/super/saccos/{$sacco->id}")
            ->assertOk()
            ->json();

        $this->assertSame($sacco->id, $data['id']);
        $this->assertSame('directory', $data['claim_status']);
        $this->assertSame(1, $data['counts']['members']);
        $this->assertSame(1, $data['counts']['drivers']);
        $this->assertSame(1, $data['counts']['vehicles']);
        $this->assertSame(1, $data['counts']['routes']);
        $this->assertSame(1, $data['counts']['trips_30d']);
        $this->assertEquals(500, $data['money']['gross_volume_30d']);
        $this->assertSame('KES', $data['money']['currency']);
        $this->assertSame(1, $data['money']['unreconciled']);
        $this->assertTrue($data['loyalty']['is_active']);
        $this->assertEquals(100, $data['loyalty']['divisor']);
        $this->assertNotEmpty($data['recent_events'], 'A freshly created directory SACCO must have at least the pending_review event.');
        $this->assertSame('sacco.pending_review.created', $data['recent_events'][0]['event']);
    }
}
