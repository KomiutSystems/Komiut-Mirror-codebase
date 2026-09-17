<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserType;
use App\Models\User;
use App\Services\Auth\AppleIdTokenVerifier;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Sign in with Apple, end to end through the real verifier.
 *
 * Apple publishes no tokeninfo endpoint, so unlike the Google test this one
 * cannot fake a verdict: it mints a P-256 key pair, publishes the public half
 * at Apple's JWKS URL (faked), and signs real ES256 tokens with the private
 * half. Every check the verifier makes -- signature, issuer, audience, expiry,
 * key id -- is exercised against a token that is genuinely right or wrong.
 */
final class AppleSignInTest extends TestCase
{
    use RefreshDatabase;

    private const SOCIAL = '/api/auth/social/apple';

    private const BUNDLE = 'com.komiut.app';

    private const KID = 'test-key-1';

    private OpenSSLAsymmetricKey $privateKey;

    protected function setUp(): void
    {
        parent::setUp();

        config(['brands.testing.apple_bundle_ids' => [self::BUNDLE]]);
        Cache::forget('apple-id-jwks');

        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $this->assertNotFalse($key, 'openssl must be able to mint a P-256 key');
        $this->privateKey = $key;
        $details = openssl_pkey_get_details($key);

        // Apple's JWKS shape: kty EC, crv P-256, x/y as base64url, alg ES256.
        Http::fake([AppleIdTokenVerifier::JWKS_URL => Http::response(['keys' => [[
            'kty' => 'EC', 'kid' => self::KID, 'use' => 'sig', 'alg' => 'ES256', 'crv' => 'P-256',
            'x' => JWT::urlsafeB64Encode($details['ec']['x']),
            'y' => JWT::urlsafeB64Encode($details['ec']['y']),
        ]]], 200)]);
    }

    /** A token as Apple would mint it for our app, with overrides for the failure cases. */
    private function token(array $overrides = [], string $kid = self::KID): string
    {
        $claims = array_merge([
            'iss' => AppleIdTokenVerifier::ISSUER,
            'aud' => self::BUNDLE,
            'exp' => time() + 600,
            'iat' => time(),
            'sub' => '001234.abcdef0123456789.1234',
            'email' => 'wanjiru@privaterelay.appleid.com',
            'email_verified' => 'true',
            'nonce_supported' => true,
        ], $overrides);

        return JWT::encode($claims, $this->privateKey, 'ES256', $kid);
    }

    #[Test]
    public function a_first_sign_in_creates_the_passenger_with_the_once_only_name(): void
    {
        $response = $this->postJson(self::SOCIAL, [
            'id_token' => $this->token(),
            'authorization_code' => 'c1a2b3',
            'given_name' => 'Wanjiru',
            'family_name' => 'Kamau',
        ])->assertOk();

        $this->assertNotEmpty($response->json('access_token'));
        $this->assertNotEmpty($response->json('refresh_token'));

        $user = User::where('provider', 'apple')->firstOrFail();
        $this->assertSame('001234.abcdef0123456789.1234', $user->provider_id);
        $this->assertSame('wanjiru@privaterelay.appleid.com', $user->email);
        $this->assertSame('Wanjiru', $user->firstname);
        $this->assertSame('Kamau', $user->lastname);
        $this->assertSame(UserType::Passenger, $user->type);
    }

    #[Test]
    public function a_repeat_sign_in_without_the_name_keeps_it(): void
    {
        $this->postJson(self::SOCIAL, ['id_token' => $this->token(), 'given_name' => 'Wanjiru', 'family_name' => 'Kamau'])->assertOk();

        // Apple never sends the name again, and may not send the email either.
        $this->postJson(self::SOCIAL, ['id_token' => $this->token(['email' => null, 'email_verified' => null])])->assertOk();

        $this->assertSame(1, User::count());
        $user = User::where('provider', 'apple')->firstOrFail();
        $this->assertSame('Wanjiru', $user->firstname);
        $this->assertSame('wanjiru@privaterelay.appleid.com', $user->email);
    }

    #[Test]
    public function a_token_minted_for_another_app_is_refused(): void
    {
        $this->postJson(self::SOCIAL, ['id_token' => $this->token(['aud' => 'com.someone.else'])])
            ->assertStatus(401)
            ->assertJsonPath('error', 'Could not verify the provider token');

        $this->assertSame(0, User::count());
    }

    #[Test]
    public function a_brand_with_no_bundle_id_accepts_nothing(): void
    {
        config(['brands.testing.apple_bundle_ids' => []]);

        $this->postJson(self::SOCIAL, ['id_token' => $this->token()])->assertStatus(401);
    }

    #[Test]
    public function an_expired_token_is_refused(): void
    {
        $this->postJson(self::SOCIAL, ['id_token' => $this->token(['exp' => time() - 60])])->assertStatus(401);
    }

    #[Test]
    public function a_token_from_another_issuer_is_refused(): void
    {
        $this->postJson(self::SOCIAL, ['id_token' => $this->token(['iss' => 'https://accounts.google.com'])])->assertStatus(401);
    }

    #[Test]
    public function a_token_signed_with_a_key_apple_does_not_publish_is_refused(): void
    {
        $other = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $forged = JWT::encode([
            'iss' => AppleIdTokenVerifier::ISSUER, 'aud' => self::BUNDLE, 'exp' => time() + 600, 'iat' => time(),
            'sub' => 'forged', 'email' => 'victim@example.test', 'email_verified' => 'true',
        ], $other, 'ES256', self::KID);

        $this->postJson(self::SOCIAL, ['id_token' => $forged])->assertStatus(401);

        // Same, but claiming a key id we have never seen: one refetch, then no.
        $this->postJson(self::SOCIAL, ['id_token' => $this->token(kid: 'unknown-kid')])->assertStatus(401);
        $this->assertSame(0, User::count());
    }

    #[Test]
    public function an_unverified_email_does_not_become_an_identity(): void
    {
        // A relay or unverified address must not claim an existing passenger's row.
        $existing = User::factory()->create(['email' => 'real@example.test', 'type' => UserType::Passenger, 'provider' => null]);

        $this->postJson(self::SOCIAL, ['id_token' => $this->token(['email' => 'real@example.test', 'email_verified' => 'false'])])
            ->assertOk();

        $this->assertNull($existing->fresh()->provider, 'the existing account must not have been linked');
        $this->assertSame(2, User::count(), 'a separate passenger was created instead');
    }

    #[Test]
    public function a_verified_email_links_an_existing_passenger(): void
    {
        $existing = User::factory()->create(['email' => 'real@example.test', 'type' => UserType::Passenger, 'provider' => null]);

        $this->postJson(self::SOCIAL, ['id_token' => $this->token(['email' => 'real@example.test'])])->assertOk();

        $this->assertSame('apple', $existing->fresh()->provider);
        $this->assertSame(1, User::count());
    }

    #[Test]
    public function staff_accounts_can_never_sign_in_with_apple(): void
    {
        User::factory()->create(['email' => 'admin@example.test', 'type' => UserType::Admin]);

        $this->postJson(self::SOCIAL, ['id_token' => $this->token(['email' => 'admin@example.test'])])
            ->assertStatus(403);
    }

    #[Test]
    public function apples_keys_are_fetched_once_and_cached(): void
    {
        $this->postJson(self::SOCIAL, ['id_token' => $this->token()])->assertOk();
        $this->postJson(self::SOCIAL, ['id_token' => $this->token()])->assertOk();

        Http::assertSentCount(1);
    }
}
