<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Crew;
use App\Models\Point;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Regression coverage for two IDORs found and fixed during the RBAC/permission
 * middleware audit: both endpoints used to trust a client-supplied identifier
 * (a user/crew id, a phone number) instead of the authenticated caller's own.
 *
 * - App\Http\Controllers\APIs\Dashboard\Profiles\ProfileAPIController::editProfile
 * - App\Http\Controllers\APIs\Dashboard\QRCode\QRCodeApiController::redeemPoints
 */
final class ProfileAndPointsIdorTest extends QueueTestCase
{
    #[Test]
    public function editing_a_profile_only_ever_touches_the_callers_own_user_record(): void
    {
        $sacco = $this->makeSacco();
        $attacker = $this->makeUser([], $sacco);
        $victim = $this->makeUser([], $sacco);

        Sanctum::actingAs($attacker);

        $this->postJson('/api/auth/profile/edit', [
            'id' => $victim->id,
            'crew_id' => 0,
            'firstname' => 'Hacked',
            'lastname' => 'Name',
            'dob' => '1991-02-02',
            'gender' => 'Male',
        ])->assertOk();

        $this->assertSame('Hacked', $attacker->fresh()->firstname);
        $this->assertNotSame('Hacked', $victim->fresh()->firstname);
    }

    #[Test]
    public function editing_a_crew_profile_is_rejected_when_the_crew_belongs_to_someone_else(): void
    {
        $sacco = $this->makeSacco();
        $attacker = $this->makeUser([], $sacco);
        $victim = $this->makeUser([], $sacco);
        $victimsCrew = Crew::create([
            'firstname' => 'Real', 'lastname' => 'Crew', 'phone' => '254711000111',
            'id_number' => '11110001', 'badge_number' => 'B-1', 'password' => 'password', 'user_id' => $victim->id, 'created_by' => $victim->id, 'status' => true,
        ]);

        Sanctum::actingAs($attacker);

        $this->postJson('/api/auth/profile/edit', [
            'id' => 0,
            'crew_id' => $victimsCrew->id,
            'firstname' => 'Hacked',
            'lastname' => 'Name',
            'dob' => '1991-02-02',
            'gender' => 'Male',
        ])->assertStatus(401);

        $this->assertSame('Real', $victimsCrew->fresh()->firstname);
    }

    #[Test]
    public function editing_a_crew_profile_the_caller_owns_succeeds(): void
    {
        $sacco = $this->makeSacco();
        $user = $this->makeUser([], $sacco);
        $crew = Crew::create([
            'firstname' => 'Original', 'lastname' => 'Crew', 'phone' => '254711000222',
            'id_number' => '11110002', 'badge_number' => 'B-2', 'password' => 'password', 'user_id' => $user->id, 'created_by' => $user->id, 'status' => true,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/auth/profile/edit', [
            'id' => 0,
            'crew_id' => $crew->id,
            'firstname' => 'Updated',
            'lastname' => 'Crew',
        ])->assertOk();

        $this->assertSame('Updated', $crew->fresh()->firstname);
    }

    /**
     * The balance these two now defend is the REAL one.
     *
     * They used to seed the legacy `points` table, which qrcode/redeem_points
     * spent from until 2026-09-10. That table has been empty since the per-SACCO
     * rewrite, so the endpoint refused everyone and the security property held
     * vacuously — it could not draw from anyone's balance because it could not
     * draw from any balance at all. The endpoint now spends from
     * loyalty_accounts, so the property has to be re-proved against the balance
     * that actually moves.
     */
    private function programFor(int $saccoId, float $threshold = 50): void
    {
        \App\Models\LoyaltyProgram::withoutGlobalScopes()->create([
            'sacco_id' => $saccoId, 'is_active' => true,
            'redemption_threshold' => $threshold, 'divisor' => 100,
            'point_value' => 3, // KES 150 named below = 50 points
        ]);
    }

    #[Test]
    public function redeeming_points_only_ever_draws_from_the_callers_own_balance(): void
    {
        $world = $this->makeWorld();
        $this->programFor((int) $world['sacco']->id);

        $attacker = $this->makeUser([], $world['sacco']);   // holds nothing
        $victim = $this->makeUser([], $world['sacco']);
        \App\Models\LoyaltyAccount::withoutGlobalScopes()->create([
            'user_id' => $victim->id, 'sacco_id' => $world['sacco']->id, 'balance' => 500,
        ]);

        Sanctum::actingAs($attacker);

        // There is no longer any way to name a payer -- no phone, no user_id --
        // so the only balance reachable is the caller's, and theirs is empty.
        $this->postJson('/api/auth/qrcode/redeem_points', [
            'vehicle_id' => $world['vehicle']->id,
            'amount' => 150,
            'user_id' => $victim->id,          // ignored; present to prove it is
        ])->assertStatus(422)
            ->assertJson(['error' => 'This ride costs 50 points and you have 0.']);

        $this->assertEqualsWithDelta(500, (float) \App\Models\LoyaltyAccount::withoutGlobalScopes()
            ->where('user_id', $victim->id)->value('balance'), 0.001,
            "the victim's balance must be untouchable");
    }

    #[Test]
    public function redeeming_points_succeeds_against_the_callers_own_balance(): void
    {
        $world = $this->makeWorld();
        $this->programFor((int) $world['sacco']->id, threshold: 50);

        $passenger = $this->makeUser([], $world['sacco']);
        \App\Models\LoyaltyAccount::withoutGlobalScopes()->create([
            'user_id' => $passenger->id, 'sacco_id' => $world['sacco']->id, 'balance' => 500,
        ]);

        Sanctum::actingAs($passenger);

        $this->postJson('/api/auth/qrcode/redeem_points', [
            'vehicle_id' => $world['vehicle']->id,
            'amount' => 150,
        ])->assertOk()
            ->assertJson(['success' => 'Ride paid with points.', 'points_spent' => 50, 'fare' => 150]);

        $this->assertEqualsWithDelta(450, (float) \App\Models\LoyaltyAccount::withoutGlobalScopes()
            ->where('user_id', $passenger->id)->value('balance'), 0.001);
    }

    #[Test]
    public function the_user_endpoint_never_returns_another_users_crew(): void
    {
        $sacco = $this->makeSacco();
        $attacker = $this->makeUser([], $sacco);
        $victim = $this->makeUser([], $sacco);
        $victimsCrew = Crew::create([
            'firstname' => 'Real', 'lastname' => 'Crew', 'phone' => '254711000333',
            'id_number' => '33330001', 'badge_number' => 'B-33', 'password' => 'password',
            'user_id' => $victim->id, 'created_by' => $victim->id, 'status' => true,
        ]);

        Sanctum::actingAs($attacker);

        // Asking for the victim's crew id must not leak it.
        $this->postJson('/api/auth/user', ['crew_id' => $victimsCrew->id])
            ->assertOk()
            ->assertJsonPath('crew', null);
    }

    #[Test]
    public function the_user_endpoint_returns_the_callers_own_crew(): void
    {
        $sacco = $this->makeSacco();
        $user = $this->makeUser([], $sacco);
        $ownCrew = Crew::create([
            'firstname' => 'Mine', 'lastname' => 'Crew', 'phone' => '254711000444',
            'id_number' => '44440001', 'badge_number' => 'B-44', 'password' => 'password',
            'user_id' => $user->id, 'created_by' => $user->id, 'status' => true,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/auth/user', ['crew_id' => $ownCrew->id])
            ->assertOk()
            ->assertJsonPath('crew.id', $ownCrew->id);
    }
}
