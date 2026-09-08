<?php

declare(strict_types=1);

namespace Tests\Feature\CarbonCredits;

use App\Models\CarbonCreditAccount;
use App\Models\CarbonCreditRedemption;
use App\Models\CarbonCreditReward;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The passenger's own reward claims.
 *
 * This endpoint had NO test at any level — it shipped, was audited, and was
 * still never called by the suite, which is how `fulfilled_at` went missing
 * from the map without anything noticing.
 *
 * That field matters more than it looks. The Activity screen merges the loyalty
 * and carbon ledgers into one chronological list, and a claim fulfilled TODAY
 * but created three weeks ago sorts by its creation date without it — so the
 * one event the passenger has actually been waiting on appears as old news,
 * buried below rides they have long forgotten.
 */
final class PassengerRedemptionsTest extends QueueTestCase
{
    private const URL = '/api/v1/auth/carbon-credits/redemptions';

    private function claim(User $user, string $status, ?Carbon $fulfilledAt = null, ?string $reference = null): CarbonCreditRedemption
    {
        $reward = CarbonCreditReward::create([
            'name' => 'KSh 50 airtime',
            'partner' => 'safaricom',
            'credits_required' => 10,
            'stock' => 5,
            'is_active' => true,
        ]);

        return CarbonCreditRedemption::create([
            'user_id' => $user->id,
            'carbon_credit_reward_id' => $reward->id,
            'credits_spent' => 10,
            'status' => $status,
            'reference' => $reference,
            'fulfilled_at' => $fulfilledAt,
        ]);
    }

    private function passenger(): User
    {
        $user = $this->makeUser();
        CarbonCreditAccount::create([
            'user_id' => $user->id, 'credits' => 0,
            'progress_cents' => 0, 'lifetime_spend_cents' => 0,
        ]);

        return $user;
    }

    #[Test]
    public function a_fulfilled_claim_reports_when_it_was_fulfilled(): void
    {
        $user = $this->passenger();
        $at = Carbon::now()->subHour();
        $this->claim($user, 'fulfilled', $at, 'SAF-77120');

        Sanctum::actingAs($user);
        $row = $this->getJson(self::URL)->assertOk()->json('redemptions.0');

        $this->assertNotNull($row['fulfilled_at'], 'a merged feed orders on this');
        $this->assertSame('SAF-77120', $row['reference']);
        $this->assertSame('fulfilled', $row['status']);
    }

    #[Test]
    public function a_pending_claim_has_no_fulfilment_time_and_no_reference(): void
    {
        // Null means "still waiting", and the client must be able to tell that
        // from "fulfilled but we lost the reference".
        $user = $this->passenger();
        $this->claim($user, 'pending');

        Sanctum::actingAs($user);
        $row = $this->getJson(self::URL)->assertOk()->json('redemptions.0');

        $this->assertNull($row['fulfilled_at']);
        $this->assertNull($row['reference'], 'a reference is withheld until it is actually fulfilled');
        $this->assertSame('pending', $row['status']);
    }

    #[Test]
    public function a_passenger_never_sees_another_passengers_claims(): void
    {
        $mine = $this->passenger();
        $theirs = $this->passenger();
        $hidden = $this->claim($theirs, 'fulfilled', Carbon::now());

        Sanctum::actingAs($mine);
        $ids = array_column($this->getJson(self::URL)->assertOk()->json('redemptions'), 'id');

        $this->assertNotContains($hidden->id, $ids);
    }

    #[Test]
    public function a_passenger_with_no_claims_gets_an_empty_list_not_an_error(): void
    {
        Sanctum::actingAs($this->passenger());

        $this->getJson(self::URL)->assertOk()->assertJsonPath('redemptions', []);
    }

    #[Test]
    public function it_needs_authentication(): void
    {
        $this->getJson(self::URL)->assertStatus(401);
    }
}
