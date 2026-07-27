<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Google ID-token sign-in.
 *
 * The property under test is the audience check. Signing a user in from a bare
 * access token proves who they are but not which app asked — so a token another
 * Google app obtained legitimately could be replayed here. An ID token names the
 * client id it was issued to, and we only accept our own.
 */
final class GoogleIdTokenTest extends TestCase
{
    use RefreshDatabase;

    private const SOCIAL = '/api/auth/social/google';

    private const OURS = 'ours.apps.googleusercontent.com';

    protected function setUp(): void
    {
        parent::setUp();
        // tests/TestCase registers the `testing` brand; give it our client id.
        config(['brands.testing.google_client_ids' => [self::OURS]]);
    }

    /** Google's tokeninfo reply for a token issued to $audience. */
    private function fakeTokenInfo(string $audience, bool $emailVerified = true): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response([
            'iss' => 'https://accounts.google.com',
            'aud' => $audience,
            'sub' => '1234567890',
            'email' => 'passenger@example.test',
            'email_verified' => $emailVerified ? 'true' : 'false',
            'name' => 'Jane Wanjiru',
        ], 200)]);
    }

    #[Test]
    public function a_token_issued_to_us_signs_the_passenger_in(): void
    {
        $this->fakeTokenInfo(self::OURS);

        $response = $this->postJson(self::SOCIAL, ['id_token' => 'any-opaque-string'])->assertOk();

        $this->assertNotEmpty($response->json('access_token'));
        $user = User::where('email', 'passenger@example.test')->firstOrFail();
        $this->assertSame(UserType::Passenger, $user->type);
        $this->assertSame('google', $user->provider);
    }

    #[Test]
    public function a_token_minted_for_another_app_is_rejected(): void
    {
        // Valid, genuinely Google-signed — but issued to somebody else.
        $this->fakeTokenInfo('someone-elses-app.apps.googleusercontent.com');

        $this->postJson(self::SOCIAL, ['id_token' => 'any-opaque-string'])
            ->assertStatus(401);

        $this->assertSame(0, User::count(), 'no account may be created from a foreign token');
    }

    #[Test]
    public function a_brand_with_no_configured_client_ids_accepts_nothing(): void
    {
        config(['brands.testing.google_client_ids' => []]);
        $this->fakeTokenInfo(self::OURS);

        $this->postJson(self::SOCIAL, ['id_token' => 'any-opaque-string'])->assertStatus(401);
    }

    #[Test]
    public function an_unverified_google_email_is_not_used_as_an_identity(): void
    {
        // Otherwise an account with an unconfirmed address could claim a real
        // passenger's row by matching on email.
        $existing = User::create([
            'firstname' => 'Real', 'lastname' => 'Passenger',
            'email' => 'passenger@example.test', 'phone' => '0700111222',
            'password' => 'password', 'type' => UserType::Passenger, 'status' => true,
        ]);
        $this->fakeTokenInfo(self::OURS, emailVerified: false);

        $this->postJson(self::SOCIAL, ['id_token' => 'any-opaque-string'])->assertOk();

        // A new account was made instead of hijacking the existing one.
        $this->assertNull($existing->fresh()->provider);
    }

    #[Test]
    public function a_rejected_token_from_google_is_a_401(): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_token'], 400)]);

        $this->postJson(self::SOCIAL, ['id_token' => 'expired-or-forged'])->assertStatus(401);
    }

    #[Test]
    public function either_token_kind_is_accepted_but_one_is_required(): void
    {
        $this->postJson(self::SOCIAL, [])->assertStatus(400);
    }
}
