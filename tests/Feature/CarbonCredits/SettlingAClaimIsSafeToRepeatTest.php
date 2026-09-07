<?php

declare(strict_types=1);

namespace Tests\Feature\CarbonCredits;

use App\Enums\UserType;
use App\Models\CarbonCreditAccount;
use App\Models\CarbonCreditRedemption;
use App\Models\CarbonCreditReward;
use App\Models\CarbonCreditTransaction;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Settling a reward claim twice must not pay twice.
 *
 * fulfil() and cancel() read their terminal-state guard off the in-memory model
 * the controller had already loaded — OUTSIDE and BEFORE the transaction — and
 * nothing locked the claim row. Two operators working the same pending queue, an
 * admin double-clicking, or a retried request all saw `pending` and all
 * proceeded.
 *
 * ON CANCEL THAT MINTED MONEY. Each pass added credits_spent back to the balance
 * and wrote another Refunded row, so credits earned once came back twice and the
 * account drifted above anything the ledger could justify. Carbon credits are a
 * liability the platform honours with real airtime and real vouchers, so an
 * accidental double-refund is not a cosmetic bug.
 *
 * The queue is worked by hand — there is no partner API behind RewardPartner, so
 * a human reads this list and sends the thing — which makes a double-click the
 * ordinary case rather than the exotic one.
 *
 * These also cover POST super/carbon-credits/redemptions/settle at the HTTP
 * level for the first time. Fulfilment was previously asserted only by calling
 * the service object directly, so the endpoint an operator actually uses — its
 * validation, its guards, its wiring — was never exercised.
 */
final class SettlingAClaimIsSafeToRepeatTest extends QueueTestCase
{
    private const URL = '/api/v1/super/carbon-credits/redemptions/settle';

    /**
     * The super role ALONE is not enough here. routes/super/carbon-credits.php
     * wraps all five endpoints in `permission:View Platform Notifications` on
     * top of the group's own `super` guard, so a super admin without that
     * permission gets 403 from the console they nominally own.
     */
    private function superAdmin(): User
    {
        $user = $this->makeUser();
        $user->forceFill(['type' => UserType::Superadmin])->save();
        Permission::findOrCreate('View Platform Notifications', 'web');
        $user->givePermissionTo('View Platform Notifications');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    /** A passenger holding a pending claim on a stocked reward. */
    private function pendingClaim(int $creditsSpent = 10, ?int $stock = 5): array
    {
        $passenger = $this->makeUser();

        $reward = CarbonCreditReward::create([
            'name' => 'KSh 50 airtime',
            'partner' => 'safaricom',
            'credits_required' => $creditsSpent,
            'stock' => $stock,
            'is_active' => true,
        ]);

        // The passenger has already paid: credits are debited at claim time.
        CarbonCreditAccount::create([
            'user_id' => $passenger->id,
            'credits' => 0,
            'progress_cents' => 0,
            'lifetime_spend_cents' => 0,
        ]);

        $claim = CarbonCreditRedemption::create([
            'user_id' => $passenger->id,
            'carbon_credit_reward_id' => $reward->id,
            'credits_spent' => $creditsSpent,
            'status' => 'pending',
        ]);

        return [$passenger, $reward, $claim];
    }

    private function creditsOf(User $user): int
    {
        return (int) CarbonCreditAccount::where('user_id', $user->id)->value('credits');
    }

    #[Test]
    public function an_operator_can_settle_a_claim_over_the_api(): void
    {
        // The endpoint a human actually uses, exercised end to end for the first
        // time — not the service object underneath it.
        [, , $claim] = $this->pendingClaim();

        Sanctum::actingAs($this->superAdmin());
        $this->postJson(self::URL, [
            'id' => $claim->id,
            'action' => 'fulfil',
            'reference' => 'SAF-77120',
        ])->assertOk()->assertJsonPath('success', 'Marked delivered.');

        $claim->refresh();
        $this->assertSame('fulfilled', $claim->status->value);
        $this->assertSame('SAF-77120', $claim->reference);
        $this->assertNotNull($claim->fulfilled_at);
    }

    #[Test]
    public function settling_the_same_claim_twice_delivers_it_once(): void
    {
        [, , $claim] = $this->pendingClaim();

        Sanctum::actingAs($this->superAdmin());
        $body = ['id' => $claim->id, 'action' => 'fulfil', 'reference' => 'SAF-77120'];

        $this->postJson(self::URL, $body)->assertOk();
        $this->postJson(self::URL, $body)->assertStatus(422);

        $this->assertSame('SAF-77120', $claim->fresh()->reference, 'the first reference stands');
    }

    #[Test]
    public function cancelling_the_same_claim_twice_refunds_the_credits_once(): void
    {
        // THE ONE THAT MINTED MONEY. Two passes each added credits_spent back.
        [$passenger, , $claim] = $this->pendingClaim(creditsSpent: 10);

        Sanctum::actingAs($this->superAdmin());
        $body = ['id' => $claim->id, 'action' => 'cancel', 'reason' => 'Out of stock'];

        $this->postJson(self::URL, $body)->assertOk();
        $this->postJson(self::URL, $body)->assertStatus(422);

        $this->assertSame(10, $this->creditsOf($passenger), 'refunded once, not twice');
        $this->assertSame(1, CarbonCreditTransaction::where('user_id', $passenger->id)
            ->where('type', 'refunded')->count(), 'one refund row, not two');
    }

    #[Test]
    public function cancelling_twice_returns_the_stock_once(): void
    {
        // A second refund also handed the catalogue back a unit it never lost,
        // so the platform would promise more rewards than it holds.
        [, $reward, $claim] = $this->pendingClaim(stock: 5);

        Sanctum::actingAs($this->superAdmin());
        $body = ['id' => $claim->id, 'action' => 'cancel'];

        $this->postJson(self::URL, $body)->assertOk();
        $this->postJson(self::URL, $body)->assertStatus(422);

        $this->assertSame(6, (int) $reward->fresh()->stock);
    }

    #[Test]
    public function a_fulfilled_claim_cannot_then_be_cancelled_for_a_refund(): void
    {
        // The dangerous crossover: deliver the airtime, then refund the credits
        // too, and the passenger has both.
        [$passenger, , $claim] = $this->pendingClaim(creditsSpent: 10);

        Sanctum::actingAs($this->superAdmin());
        $this->postJson(self::URL, ['id' => $claim->id, 'action' => 'fulfil'])->assertOk();
        $this->postJson(self::URL, ['id' => $claim->id, 'action' => 'cancel'])->assertStatus(422);

        $this->assertSame(0, $this->creditsOf($passenger), 'delivered means spent');
        $this->assertSame('fulfilled', $claim->fresh()->status->value);
    }

    #[Test]
    public function a_cancelled_claim_cannot_then_be_marked_delivered(): void
    {
        [$passenger, , $claim] = $this->pendingClaim(creditsSpent: 10);

        Sanctum::actingAs($this->superAdmin());
        $this->postJson(self::URL, ['id' => $claim->id, 'action' => 'cancel'])->assertOk();
        $this->postJson(self::URL, ['id' => $claim->id, 'action' => 'fulfil'])->assertStatus(422);

        $this->assertSame(10, $this->creditsOf($passenger), 'the refund stands and is not spent again');
        $this->assertSame('cancelled', $claim->fresh()->status->value);
    }

    #[Test]
    public function an_ordinary_passenger_cannot_settle_a_claim(): void
    {
        // The queue moves real money. The only guard on it is the super role.
        [, , $claim] = $this->pendingClaim();

        Sanctum::actingAs($this->makeUser());
        $this->postJson(self::URL, ['id' => $claim->id, 'action' => 'fulfil'])->assertStatus(403);

        $this->assertSame('pending', $claim->fresh()->status->value);
    }
}
