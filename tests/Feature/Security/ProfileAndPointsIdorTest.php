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

    #[Test]
    public function redeeming_points_only_ever_draws_from_the_callers_own_phone(): void
    {
        $world = $this->makeWorld();
        $attacker = $this->makeUser([], $world['sacco']);
        Point::create([
            'phone' => '254799999999', // a phone that is NOT the attacker's
            'name' => 'Victim', 'points' => 500, 'redeemed' => 0,
            'start_date' => now(), 'end_date' => now()->addYear(), 'sacco_id' => $world['sacco']->id, 'status' => true,
        ]);

        Sanctum::actingAs($attacker);

        $this->postJson('/api/auth/qrcode/redeem_points', [
            'vehicle_id' => $world['vehicle']->id,
        ])->assertStatus(401)
            ->assertJson(['error' => 'You do not have enough points to proceed!']);
    }

    #[Test]
    public function redeeming_points_succeeds_against_the_callers_own_balance(): void
    {
        $world = $this->makeWorld();
        $attacker = $this->makeUser([], $world['sacco']);
        $ownPoints = Point::create([
            'phone' => $attacker->phone,
            'name' => 'Me', 'points' => 500, 'redeemed' => 0,
            'start_date' => now(), 'end_date' => now()->addYear(), 'sacco_id' => $world['sacco']->id, 'status' => true,
        ]);

        Sanctum::actingAs($attacker);

        $this->postJson('/api/auth/qrcode/redeem_points', [
            'vehicle_id' => $world['vehicle']->id,
        ])->assertOk()
            ->assertJson(['success' => 'Points Redeemed successfully']);

        $this->assertEquals(450, $ownPoints->fresh()->points);
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
