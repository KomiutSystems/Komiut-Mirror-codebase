<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Verifies a Google ID token and, critically, that it was minted FOR US.
 *
 * The weakness of signing a user in from a bare access token is that the token
 * says who the user is but not which app asked for it. Any other Google app
 * could hand us a token it obtained legitimately and we would sign that person
 * in — a token-substitution attack. An ID token carries an `aud` claim naming
 * the client id it was issued to, so checking `aud` against this brand's own
 * client ids closes that hole.
 *
 * Verification is delegated to Google's tokeninfo endpoint, which checks the
 * signature and expiry for us — no key handling, no new dependency. The
 * audience check is ours because only we know which client ids are ours.
 */
final class GoogleIdTokenVerifier
{
    private const TOKENINFO = 'https://oauth2.googleapis.com/tokeninfo';

    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    /**
     * @return array{sub: string, email: string|null, name: string|null}|null
     *         null when the token is invalid, expired, or not addressed to us
     */
    public function verify(string $idToken): ?array
    {
        $claims = $this->fetchClaims($idToken);

        if ($claims === null) {
            return null;
        }

        if (! in_array((string) ($claims['iss'] ?? ''), self::ISSUERS, true)) {
            return null;
        }

        if (! $this->addressedToUs((string) ($claims['aud'] ?? ''))) {
            return null;
        }

        // Google only returns a payload for a token that verified, but an
        // unverified email must not become an account identity: it would let a
        // provider account with an unconfirmed address claim a real user's row.
        $email = isset($claims['email']) && ($claims['email_verified'] ?? 'false') !== 'false'
            ? (string) $claims['email']
            : null;

        return [
            'sub' => (string) $claims['sub'],
            'email' => $email,
            'name' => isset($claims['name']) ? (string) $claims['name'] : null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function fetchClaims(string $idToken): ?array
    {
        try {
            $response = Http::timeout(8)->get(self::TOKENINFO, ['id_token' => $idToken]);
        } catch (RuntimeException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $claims = $response->json();

        return is_array($claims) && isset($claims['sub']) ? $claims : null;
    }

    /**
     * Whether the token's audience is one of the current brand's client ids.
     *
     * Fails closed: a brand with no configured client ids accepts nothing,
     * rather than accepting everything.
     */
    private function addressedToUs(string $audience): bool
    {
        if ($audience === '') {
            return false;
        }

        $brand = Context::has('brand') ? (string) Context::get('brand') : null;
        $accepted = (array) config("brands.{$brand}.google_client_ids", []);

        foreach ($accepted as $clientId) {
            if (hash_equals((string) $clientId, $audience)) {
                return true;
            }
        }

        return false;
    }
}
