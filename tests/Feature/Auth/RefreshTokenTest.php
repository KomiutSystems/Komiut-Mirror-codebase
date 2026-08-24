<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\RefreshToken;
use App\Models\User;
use App\Services\Auth\TokenPair;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The refresh flow that replaced a daily login.
 *
 * Before this, an access token died after 24 hours (config sanctum.expiration)
 * and the `refresh` endpoint called `auth()->refresh()` -- tymon/jwt-auth API
 * for a package this project has never had installed -- so it returned a 500
 * with a live token and a 401 without one. There was no way to stay signed in.
 *
 * This REPLACES the three tests added with the Sanctum-rotation fix. That
 * version mints a new PAT and revokes the current one, which is correct as far
 * as it goes but still sits behind auth:sanctum -- so it needs a LIVE access
 * token and cannot rescue a session that already expired, which is the only
 * situation anyone reaches for refresh in. Its assertions (that the OLD access
 * token dies, and that exactly one token survives) describe behaviour this
 * change deliberately drops: the caller now presents a refresh token, not an
 * access token, so there is no current access token to revoke.
 *
 * The security property these tests exist to hold is the one in the migration:
 * a refresh token must be able to buy a new access token and NOTHING else. It
 * is not a Sanctum PAT, so it can never authenticate a request.
 */
final class RefreshTokenTest extends TestCase
{
    use RefreshDatabase;

    private const LOGIN = '/api/auth/login';

    private const REFRESH = '/api/auth/refresh';

    private const LOGOUT = '/api/auth/logout';

    /** A route that requires a working access token. */
    private const GUARDED = '/api/auth/user';

    private function passenger(string $password = 'secret123'): User
    {
        return (new UserFactory)->create([
            'email' => 'rider@example.test',
            'password' => $password,
            'status' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function login(string $password = 'secret123'): array
    {
        $this->passenger($password);

        return $this->postJson(self::LOGIN, [
            'email' => 'rider@example.test',
            'password' => $password,
        ])->assertOk()->json();
    }

    #[Test]
    public function logging_in_returns_a_refresh_token_alongside_the_access_token(): void
    {
        $body = $this->login();

        $this->assertNotEmpty($body['access_token']);
        $this->assertNotEmpty($body['refresh_token'], 'Login must issue a refresh token.');
        $this->assertNotEmpty($body['refresh_expires_at']);

        // The old keys are untouched, so existing clients keep working.
        $this->assertSame('bearer', $body['token_type']);
    }

    #[Test]
    public function a_refresh_token_buys_a_new_working_access_token(): void
    {
        $first = $this->login();

        $second = $this->postJson(self::REFRESH, ['refresh_token' => $first['refresh_token']])
            ->assertOk()
            ->json();

        $this->assertNotSame($first['access_token'], $second['access_token']);
        $this->assertNotSame($first['refresh_token'], $second['refresh_token'], 'Rotation must issue a NEW refresh token.');

        // The point of the whole exercise: the new access token actually works.
        $this->withHeaders(['Authorization' => 'Bearer '.$second['access_token']])
            ->postJson(self::GUARDED)
            ->assertOk();
    }

    #[Test]
    public function refreshing_works_even_when_the_access_token_is_already_dead(): void
    {
        // THE scenario. The user comes back the next morning: the access token
        // expired overnight and the only credential still alive is the refresh
        // token. If this needed a live access token it would be useless.
        // Tokens are issued directly rather than through the login ENDPOINT:
        // logging in over HTTP also opens a session, and Sanctum's guard falls
        // back to the session for first-party requests, so a later request
        // would authenticate through that instead of the bearer token under
        // test and never reach the 401 this asserts.
        $user = $this->passenger();
        $body = TokenPair::issue($user, TokenPair::nameFor($user));

        $user->tokens()->update(['expires_at' => Carbon::now()->subMinute()]);

        $this->withHeaders(['Authorization' => 'Bearer '.$body['access_token']])
            ->postJson(self::GUARDED)
            ->assertUnauthorized();

        $this->postJson(self::REFRESH, ['refresh_token' => $body['refresh_token']])->assertOk();
    }

    #[Test]
    public function a_refresh_token_cannot_be_used_as_an_access_token(): void
    {
        // The property the dedicated table exists for. Had this been minted as
        // a Sanctum PAT with a 'refresh' ability, it would authenticate every
        // route in the API, because auth:sanctum never checks abilities.
        // Issued directly, for the same session reason as above.
        $user = $this->passenger();
        $body = TokenPair::issue($user, TokenPair::nameFor($user));

        $this->withHeaders(['Authorization' => 'Bearer '.$body['refresh_token']])
            ->postJson(self::GUARDED)
            ->assertUnauthorized();

        // And the control: the ACCESS token from the same pair does work, so
        // the 401 above is the refresh token being rejected, not a broken route.
        $this->withHeaders(['Authorization' => 'Bearer '.$body['access_token']])
            ->postJson(self::GUARDED)
            ->assertOk();
    }

    #[Test]
    public function a_refresh_token_is_single_use(): void
    {
        $body = $this->login();

        $this->postJson(self::REFRESH, ['refresh_token' => $body['refresh_token']])->assertOk();
        $this->postJson(self::REFRESH, ['refresh_token' => $body['refresh_token']])->assertUnauthorized();
    }

    #[Test]
    public function replaying_a_spent_token_revokes_the_whole_chain(): void
    {
        // A spent token coming back is either a replay or a client that lost a
        // response. Either way the chain is no longer trustworthy, and the
        // holder can simply sign in again.
        $first = $this->login();

        $second = $this->postJson(self::REFRESH, ['refresh_token' => $first['refresh_token']])
            ->assertOk()->json();

        $this->postJson(self::REFRESH, ['refresh_token' => $first['refresh_token']])->assertUnauthorized();

        // The token issued by the legitimate rotation is dead too.
        $this->postJson(self::REFRESH, ['refresh_token' => $second['refresh_token']])->assertUnauthorized();
    }

    #[Test]
    public function an_expired_refresh_token_is_refused(): void
    {
        $body = $this->login();

        RefreshToken::query()->update(['expires_at' => Carbon::now()->subDay()]);

        $this->postJson(self::REFRESH, ['refresh_token' => $body['refresh_token']])
            ->assertUnauthorized();
    }

    #[Test]
    public function an_unknown_or_empty_refresh_token_is_refused_without_saying_why(): void
    {
        $this->login();

        foreach (['', '   ', 'not-a-real-token'] as $garbage) {
            $this->postJson(self::REFRESH, ['refresh_token' => $garbage])
                ->assertUnauthorized()
                ->assertJsonPath('error', 'That session has expired. Please sign in again.');
        }
    }

    #[Test]
    public function a_suspended_account_cannot_refresh(): void
    {
        $body = $this->login();

        User::where('email', 'rider@example.test')->update(['status' => false]);

        $this->postJson(self::REFRESH, ['refresh_token' => $body['refresh_token']])
            ->assertUnauthorized();
    }

    #[Test]
    public function logging_out_kills_the_refresh_token_too(): void
    {
        // tokens()->delete() only reaches Sanctum PATs. Without the explicit
        // revoke, logout would leave a credential behind that mints a new
        // access token straight afterwards.
        $body = $this->login();

        $this->withHeaders(['Authorization' => 'Bearer '.$body['access_token']])
            ->postJson(self::LOGOUT)
            ->assertOk();

        $this->postJson(self::REFRESH, ['refresh_token' => $body['refresh_token']])
            ->assertUnauthorized();
    }

    #[Test]
    public function revoking_for_a_user_leaves_other_users_alone(): void
    {
        $mine = $this->login();

        $other = (new UserFactory)->create(['email' => 'other@example.test', 'status' => true]);
        $otherTokens = TokenPair::issue($other, TokenPair::nameFor($other));

        TokenPair::revokeAllFor(User::where('email', 'rider@example.test')->firstOrFail());

        $this->postJson(self::REFRESH, ['refresh_token' => $mine['refresh_token']])->assertUnauthorized();
        $this->postJson(self::REFRESH, ['refresh_token' => $otherTokens['refresh_token']])->assertOk();
    }

    #[Test]
    public function only_the_hash_is_stored(): void
    {
        // The plaintext outlives the access token by weeks, so a database read
        // must not hand someone a month of sessions.
        $body = $this->login();

        $this->assertDatabaseMissing('refresh_tokens', ['token_hash' => $body['refresh_token']]);
        $this->assertDatabaseHas('refresh_tokens', ['token_hash' => hash('sha256', $body['refresh_token'])]);
    }
}
