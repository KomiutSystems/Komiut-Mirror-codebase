<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserType;
use App\Models\FirebaseToken;
use App\Models\User;
use App\Services\Auth\TokenPair;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Feature\Queues\QueueTestCase;

/**
 * How long a session lasts, and what signing out actually ends.
 *
 * TWO SEPARATE COMPLAINTS, one cause each.
 *
 * "We keep having to log in." The access token lasted 24 hours. A refresh token
 * valid 30 days already existed, so an unbroken session was possible — but only
 * for a client that implements the exchange. Crew now get a week outright.
 *
 * "Logging out on my phone signed me out everywhere." Both the PAT delete and
 * the push-token delete were unscoped, so one handset ended every session and
 * silenced notifications on devices the person was still carrying.
 */
final class SessionLifetimeTest extends QueueTestCase
{
    private function crew(): User
    {
        $u = $this->makeUser([], null);
        $u->forceFill(['type' => UserType::Driver])->save();

        return $u->fresh();
    }

    private function staff(array $world): User
    {
        $u = $this->makeUser([], $world['sacco']);
        $u->forceFill(['type' => UserType::Admin, 'sacco_id' => $world['sacco']->id])->save();

        return $u->fresh();
    }

    #[Test]
    public function a_driver_stays_signed_in_for_a_week(): void
    {
        // A driver starts a shift at five in the morning at a stage, on a phone
        // with poor signal. Being logged out there costs real time.
        $pair = TokenPair::issue($this->crew(), 'test');

        $expires = \Illuminate\Support\Carbon::parse($pair['expires_at']);

        $this->assertTrue($expires->greaterThan(now()->addDays(6)), 'a week, not a day');
        $this->assertTrue($expires->lessThan(now()->addDays(8)));
    }

    #[Test]
    public function a_passenger_gets_the_same_long_session(): void
    {
        $u = $this->makeUser([], null);
        $u->forceFill(['type' => UserType::Passenger])->save();

        $pair = TokenPair::issue($u->fresh(), 'test');

        $this->assertTrue(
            \Illuminate\Support\Carbon::parse($pair['expires_at'])->greaterThan(now()->addDays(6))
        );
    }

    #[Test]
    public function an_admin_does_not_get_a_week(): void
    {
        // An admin token opens the takings and the tills that decide where fares
        // land. A stolen admin phone is a much bigger prize than a driver's, and
        // staff sign in at a desk on a keyboard — the cheapest re-login here.
        $world = $this->makeWorld();

        $pair = TokenPair::issue($this->staff($world), 'test');

        $expires = $pair['expires_at'] === null
            ? null
            : \Illuminate\Support\Carbon::parse($pair['expires_at']);

        $this->assertTrue(
            $expires === null || $expires->lessThan(now()->addDays(2)),
            'staff keep the configured short lifetime'
        );
    }

    #[Test]
    public function holding_a_role_is_enough_to_be_treated_as_staff(): void
    {
        // `type` is one nullable column. Someone carrying a role has dashboard
        // capability whatever that column says.
        $world = $this->makeWorld();
        $u = $this->makeUser([], $world['sacco']);
        $u->forceFill(['type' => UserType::Passenger])->save();
        Role::findOrCreate('Sacco Admin', 'web');
        $u->assignRole('Sacco Admin');

        $pair = TokenPair::issue($u->fresh(), 'test');

        $expires = $pair['expires_at'] === null ? null : \Illuminate\Support\Carbon::parse($pair['expires_at']);
        $this->assertTrue($expires === null || $expires->lessThan(now()->addDays(2)));
    }

    #[Test]
    public function the_refresh_token_still_outlives_the_access_token(): void
    {
        // The long session survives even the week, for a client that refreshes.
        $pair = TokenPair::issue($this->crew(), 'test');

        $this->assertTrue(
            \Illuminate\Support\Carbon::parse($pair['refresh_expires_at'])
                ->greaterThan(\Illuminate\Support\Carbon::parse($pair['expires_at']))
        );
    }

    #[Test]
    public function signing_out_on_one_device_leaves_the_other_signed_in(): void
    {
        $user = $this->crew();

        $phone = $user->createToken('phone')->plainTextToken;
        $tablet = $user->createToken('tablet');

        Sanctum::actingAs($user, ['*']);
        $user->withAccessToken($tablet->accessToken);

        $this->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertSame(
            1,
            $user->fresh()->tokens()->count(),
            'the other device must keep its session'
        );
        $this->assertNotNull($phone);
    }

    #[Test]
    public function signing_out_does_not_silence_push_on_every_device(): void
    {
        $user = $this->crew();

        FirebaseToken::create(['user_id' => $user->id, 'device_id' => 'phone', 'firebase_token' => 'tok-phone']);
        FirebaseToken::create(['user_id' => $user->id, 'device_id' => 'tablet', 'firebase_token' => 'tok-tablet']);

        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/v1/auth/logout', ['device_id' => 'phone'])->assertOk();

        $left = FirebaseToken::where('user_id', $user->id)->pluck('device_id')->all();

        $this->assertSame(['tablet'], $left, 'only the device signing out loses push');
    }

    #[Test]
    public function signing_out_everywhere_still_ends_every_session(): void
    {
        // The case that genuinely wants the old behaviour: a lost or stolen
        // handset, where the point is to end everything.
        $user = $this->crew();
        $user->createToken('phone');
        $user->createToken('tablet');
        FirebaseToken::create(['user_id' => $user->id, 'device_id' => 'phone', 'firebase_token' => 'tok-phone']);

        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/v1/auth/logout', ['all' => true])
            ->assertOk()
            ->assertJsonPath('message', 'Signed out on every device');

        $this->assertSame(0, $user->fresh()->tokens()->count());
        $this->assertSame(0, FirebaseToken::where('user_id', $user->id)->count());
    }
}
