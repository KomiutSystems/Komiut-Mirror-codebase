<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\UserType;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Issues and rotates the access/refresh pair.
 *
 * Why this exists: the dashboard was logging people out every day. Sanctum
 * access tokens expire after `sanctum.expiration` minutes (24 hours here), the
 * old `refresh` endpoint was tymon/jwt-auth code (`auth()->refresh()`) for a
 * package this project has never installed, and the web only checked whether
 * its cookie still existed — so the dashboard stayed open while every request
 * behind it returned 401.
 *
 * The pair:
 *
 *   ACCESS   a normal Sanctum PAT. Short-lived, sent on every request, and the
 *            only thing `auth:sanctum` ever accepts.
 *   REFRESH  a random string in `refresh_tokens`, stored only as a SHA-256.
 *            Long-lived, sent to exactly one endpoint, and unable to
 *            authenticate anything (see the migration for why that matters).
 *
 * Rotation is single-use: exchanging a refresh token revokes it and issues a
 * new pair. A token presented twice is therefore recognisable, which is the
 * cheapest theft signal available without device fingerprinting.
 */
final class TokenPair
{
    /** Refresh lifetime in minutes. 30 days — long enough that nobody logs in daily. */
    private const REFRESH_MINUTES = 43200;

    /**
     * Access lifetime for CREW and passengers: 7 days.
     *
     * A driver starts a shift at five in the morning at a stage, on a handset
     * with poor signal, and signing in again there costs real time. Their token
     * opens their own bus and nothing else — the trip they are running, its
     * passengers, its takings — so a week is a proportionate trade for not
     * being locked out at dawn.
     *
     * The refresh token already made an unbroken session possible without this,
     * but only for a client that implements the exchange. This makes the plain
     * case work too.
     */
    private const ACCESS_MINUTES_CREW = 10080;

    /**
     * Access lifetime for STAFF: whatever config says, 24h by default.
     *
     * Deliberately NOT a week. An admin token opens every screen in the SACCO —
     * the takings, the tills that decide where fares land, the member list — so
     * a stolen phone is a much larger prize than a driver's. Staff sign in on a
     * dashboard, at a desk, on a keyboard, which is the cheapest re-login on the
     * platform rather than the most expensive.
     */
    private static function accessMinutesFor(User $user): ?int
    {
        $staff = in_array($user->type, [UserType::Admin, UserType::Superadmin], true)
            || $user->roles()->exists()
            || $user->permissions()->exists();

        return $staff ? null : self::ACCESS_MINUTES_CREW;
    }

    /**
     * A fresh access + refresh pair. The refresh plaintext is returned here and
     * nowhere else; only its hash is stored.
     *
     * @return array{access_token: string, refresh_token: string, expires_at: string|null, refresh_expires_at: string}
     */
    public static function issue(User $user, string $name): array
    {
        // Null expiry hands the decision to config('sanctum.expiration'), which
        // is what every existing call site already relied on — that stays true
        // for staff. Crew and passengers get an explicit longer expiry instead;
        // see accessMinutesFor() for why the two differ.
        $minutes = self::accessMinutesFor($user);
        $expiresAt = $minutes === null ? null : Carbon::now()->addMinutes($minutes);

        $token = $user->createToken($name, ['*'], $expiresAt);
        $access = $token->plainTextToken;

        $plain = Str::random(64);
        $expiresAt = Carbon::now()->addMinutes(self::REFRESH_MINUTES);

        RefreshToken::create([
            'user_id' => $user->id,
            'token_hash' => self::hash($plain),
            'expires_at' => $expiresAt,
        ]);

        return [
            'access_token' => $access,
            'refresh_token' => $plain,
            'expires_at' => ($expiresAt ?? self::accessExpiresAt())?->toIso8601String(),
            'refresh_expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * Exchange a refresh token for a new pair, or null if it will not do.
     *
     * Null covers every failure the caller must treat identically — unknown,
     * expired, already spent — because telling them apart would let someone
     * probe which of their stolen strings was once real.
     *
     * @return array{user: User, tokens: array<string, string|null>}|null
     */
    public static function rotate(string $presented): ?array
    {
        $presented = trim($presented);

        if ($presented === '') {
            return null;
        }

        $row = RefreshToken::where('token_hash', self::hash($presented))->first();

        if ($row === null || ! $row->isUsable()) {
            // A REVOKED row presented again means either a replay or a client
            // that retried a request whose response it never saw. Both are
            // reasons to stop trusting the chain, not to quietly re-issue: the
            // holder still has a valid login and can sign in again.
            if ($row !== null && $row->revoked_at !== null) {
                self::revokeAllFor($row->user_id);
            }

            return null;
        }

        $user = $row->user;

        if ($user === null || ! $user->status) {
            return null;
        }

        // Single use. Revoked BEFORE the new pair exists, so a crash between
        // the two leaves the caller logged out rather than holding two live
        // refresh tokens.
        $row->forceFill(['revoked_at' => Carbon::now(), 'last_used_at' => Carbon::now()])->save();

        return [
            'user' => $user,
            'tokens' => self::issue($user, self::nameFor($user)),
        ];
    }

    /**
     * Drop every refresh token a user holds.
     *
     * Called wherever access tokens are already being dropped — logout, and the
     * driver's one-live-session rule. Those paths use `$user->tokens()->delete()`,
     * which only reaches Sanctum PATs; without this the refresh token would
     * outlive the session it belonged to and could mint a new access token for
     * a driver who had been signed out by the next driver.
     */
    public static function revokeAllFor(int|User $user): void
    {
        RefreshToken::where('user_id', $user instanceof User ? $user->id : $user)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => Carbon::now()]);
    }

    /** The token name used at every issue site, so tokens stay recognisable. */
    public static function nameFor(User $user): string
    {
        return ($user->firstname ?: 'user').'-AuthToken';
    }

    private static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /** When the access token dies, or null when Sanctum is configured not to expire them. */
    private static function accessExpiresAt(): ?Carbon
    {
        $minutes = config('sanctum.expiration');

        return $minutes ? Carbon::now()->addMinutes((int) $minutes) : null;
    }
}
