<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserType;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The passenger sign-up flow: Google, then a phone, then home.
 *
 * A passenger taps "Sign in with Google", the app creates or finds the account,
 * and — because Google gives us an email and never a phone — sends them to a
 * screen to enter one. That number becomes the account's M-Pesa identity, so
 * the step is not optional and the flow cannot proceed without it.
 *
 * WHAT BROKE IT. The number is unique across users, and 6,539 of the 6,541
 * passenger accounts already hold one from before social sign-in existed. A
 * returning passenger signing up again through Google types the number they
 * have always used, hits `unique:users,phone`, and stops on the phone screen
 * with no route past it — the account is new, the number is theirs, and nothing
 * in the app can resolve it. Since re-registration is the expected path rather
 * than an edge case, that is the normal experience, not a corner.
 *
 * So a number still held by a DORMANT account — a passenger who has never
 * signed in through a provider — is released to the claimant.
 *
 * THE TRADE, STATED PLAINLY: nothing verifies the claim. Anyone who knows a
 * dormant passenger's number can take it. That is accepted while the passenger
 * base is dormant, and it stops being acceptable the moment it is not; the
 * guards below are what keep the blast radius to exactly that case, and they
 * are the reason this file exists.
 */
final class GooglePassengerPhoneTest extends QueueTestCase
{
    private const ENDPOINT = '/api/v1/auth/profile/update';

    private function passenger(array $attributes = []): User
    {
        $u = $this->makeUser([], null);
        $u->forceFill(array_merge([
            'type' => UserType::Passenger,
            'sacco_id' => null,
            'provider' => null,
            'provider_id' => null,
        ], $attributes))->save();

        return $u->fresh();
    }

    /** Freshly created by SocialAuthController: has an email, has no phone. */
    private function googleSignup(): User
    {
        return $this->passenger([
            'phone' => null,
            'provider' => 'google',
            'provider_id' => 'g-'.uniqid(),
        ]);
    }

    #[Test]
    public function a_google_passenger_can_set_their_phone(): void
    {
        // The happy path, and the whole point of the screen.
        Sanctum::actingAs($this->googleSignup());

        $this->postJson(self::ENDPOINT, ['phone' => '0712345678'])
            ->assertOk()
            ->assertJsonPath('user.phone', '0712345678');
    }

    #[Test]
    public function they_can_reclaim_the_number_from_their_own_dormant_account(): void
    {
        // THE CASE THAT WAS A DEAD END. Same person, signing up again with a
        // Google address that does not match the email they registered with
        // years ago, so no account matched and a new one was made.
        $old = $this->passenger(['phone' => '0712345678']);
        $new = $this->googleSignup();

        Sanctum::actingAs($new);

        $this->postJson(self::ENDPOINT, ['phone' => '0712345678'])
            ->assertOk()
            ->assertJsonPath('user.phone', '0712345678');

        $this->assertNull($old->fresh()->phone, 'the dormant account gives the number up');
    }

    #[Test]
    public function the_release_is_written_to_the_audit_log(): void
    {
        // A number taken without proof has to be traceable, or a disputed
        // account cannot be handed back.
        $old = $this->passenger(['phone' => '0712345678']);
        $new = $this->googleSignup();

        Sanctum::actingAs($new);
        $this->postJson(self::ENDPOINT, ['phone' => '0712345678'])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'passenger.phone_released']);
    }

    #[Test]
    public function a_number_already_linked_to_google_is_never_taken(): void
    {
        // The guard that matters most. A live social account is a real person
        // using the app; only DORMANT numbers are claimable.
        $live = $this->passenger([
            'phone' => '0712345678',
            'provider' => 'google',
            'provider_id' => 'g-someone-else',
        ]);

        Sanctum::actingAs($this->googleSignup());

        $this->postJson(self::ENDPOINT, ['phone' => '0712345678'])
            ->assertStatus(422)
            ->assertJsonPath('errors.phone.0', 'That phone number is already in use.');

        $this->assertSame('0712345678', $live->fresh()->phone, 'a live account keeps its number');
    }

    #[Test]
    public function a_staff_number_is_never_taken(): void
    {
        // A SACCO admin's number is not a dormant passenger's. Taking it would
        // detach a staff account from the phone its password reset goes to.
        $world = $this->makeWorld();
        $staff = $this->makeUser([], $world['sacco']);
        $staff->forceFill([
            'type' => UserType::Admin,
            'sacco_id' => $world['sacco']->id,
            'phone' => '0712345678',
            'provider' => null,
        ])->save();

        Sanctum::actingAs($this->googleSignup());

        $this->postJson(self::ENDPOINT, ['phone' => '0712345678'])->assertStatus(422);

        $this->assertSame('0712345678', $staff->fresh()->phone);
    }

    #[Test]
    public function a_driver_number_is_never_taken(): void
    {
        // Crew sign in with phone + plate. Releasing a driver's number would
        // lock them out of the vehicle at the stage.
        $driver = $this->makeUser([], null);
        $driver->forceFill(['type' => UserType::Driver, 'phone' => '0712345678', 'provider' => null])->save();

        Sanctum::actingAs($this->googleSignup());

        $this->postJson(self::ENDPOINT, ['phone' => '0712345678'])->assertStatus(422);

        $this->assertSame('0712345678', $driver->fresh()->phone);
    }

    #[Test]
    public function keeping_your_own_number_is_not_a_takeover(): void
    {
        // Re-saving the same number must not null it out via the release path
        // and then set it back — the caller's own row is excluded.
        $me = $this->passenger(['phone' => '0712345678', 'provider' => 'google', 'provider_id' => 'g-me']);

        Sanctum::actingAs($me);

        $this->postJson(self::ENDPOINT, ['firstname' => 'John', 'phone' => '0712345678'])
            ->assertOk()
            ->assertJsonPath('user.phone', '0712345678');

        $this->assertSame('0712345678', $me->fresh()->phone);
    }

    #[Test]
    public function any_kenyan_format_reaches_the_same_number(): void
    {
        // The app sends what the passenger typed. +254, 254, 07 and 7 are one
        // number, and the dormant-account lookup must run on the canonical form
        // or a reclaim silently fails for someone who typed +254.
        $old = $this->passenger(['phone' => '0712345678']);

        Sanctum::actingAs($this->googleSignup());

        $this->postJson(self::ENDPOINT, ['phone' => '+254712345678'])
            ->assertOk()
            ->assertJsonPath('user.phone', '0712345678');

        $this->assertNull($old->fresh()->phone);
    }

    #[Test]
    public function a_number_that_is_not_a_kenyan_mobile_is_refused(): void
    {
        Sanctum::actingAs($this->googleSignup());

        $this->postJson(self::ENDPOINT, ['phone' => '12345'])
            ->assertStatus(422)
            ->assertJsonPath('errors.phone.0', 'The phone must be a valid Kenyan mobile number.');
    }

    #[Test]
    public function the_screen_requires_a_signed_in_caller(): void
    {
        $this->postJson(self::ENDPOINT, ['phone' => '0712345678'])->assertStatus(401);
    }
}
