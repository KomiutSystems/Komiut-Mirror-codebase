<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression tests for App\Http\Controllers\APIs\AuthController::refresh().
 *
 * Sanctum has no JWT-style refresh; the endpoint ROTATES the caller's token.
 * A real bearer token is used throughout (not Sanctum::actingAs, which yields
 * a TransientToken) so the revocation of the old token is genuinely exercised.
 */
final class RefreshTokenTest extends TestCase
{
    use RefreshDatabase;

    private const string REFRESH = '/api/auth/refresh';

    private const string USER = '/api/auth/user';

    protected function setUp(): void
    {
        parent::setUp();

        // Nothing in this suite may reach the SMS gateway.
        Bus::fake();
    }

    /**
     * Mint a real personal access token the way login/register do, and return
     * the plain-text bearer string the mobile app would send.
     */
    private function issueToken(User $user): string
    {
        return $user->createToken($user->firstname.'-AuthToken')->plainTextToken;
    }

    #[Test]
    public function refresh_returns_a_new_working_token(): void
    {
        $user = User::factory()->create();
        $token = $this->issueToken($user);

        $response = $this->withToken($token)->postJson(self::REFRESH);

        $response->assertOk();
        $response->assertJsonStructure([
            'user',
            'crew',
            'permissions',
            'vehicle_users',
            'termini',
            'sacco',
            'access_token',
            'token_type',
        ]);
        $this->assertSame('bearer', $response->json('token_type'));
        $this->assertSame($user->id, $response->json('user.id'));

        $newToken = $response->json('access_token');
        $this->assertNotEmpty($newToken);
        $this->assertNotSame($token, $newToken);

        // The freshly issued token authenticates a subsequent request.
        $this->withToken($newToken)->postJson(self::USER)->assertOk();
    }

    #[Test]
    public function refresh_revokes_the_old_token(): void
    {
        $user = User::factory()->create();
        $token = $this->issueToken($user);
        // Sanctum tokens are "id|plaintext"; capture the id so we can prove the
        // exact row is gone.
        $oldId = (int) explode('|', $token, 2)[0];

        $this->withToken($token)->postJson(self::REFRESH)->assertOk();

        // Revocation is a DB fact: the old token's row is deleted and only the
        // freshly minted one remains. (We assert at the DB level rather than with
        // a second HTTP call, because Laravel's auth guard caches the resolved
        // user for the lifetime of the test's app instance — so a follow-up
        // request can appear authenticated even with a revoked token.)
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $oldId]);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    #[Test]
    public function refresh_leaves_exactly_one_live_token_for_the_caller(): void
    {
        $user = User::factory()->create();
        $token = $this->issueToken($user);
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->withToken($token)->postJson(self::REFRESH)->assertOk();

        // Old row deleted, new row created: still exactly one.
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertSame(1, $user->fresh()->tokens()->count());
    }

    #[Test]
    public function an_unauthenticated_refresh_call_returns_401(): void
    {
        $this->postJson(self::REFRESH)->assertStatus(401);
    }

    #[Test]
    public function a_refresh_with_a_bogus_token_returns_401(): void
    {
        $this->withToken('not-a-real-token')->postJson(self::REFRESH)->assertStatus(401);
    }
}
