<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Verifies a Sign in with Apple identity token and that it was minted FOR US.
 *
 * Same shape as GoogleIdTokenVerifier, different mechanics. Google offers a
 * tokeninfo endpoint that checks the signature for us; Apple does not. Apple
 * publishes its signing keys as a JWKS and the token is an ES256 JWT, so the
 * signature is checked here against those keys, and the rest of the checks
 * are the standard ones:
 *
 *   iss  https://appleid.apple.com
 *   aud  this brand's iOS bundle id (com.komiut.app) -- with Apple the
 *        audience of a NATIVE sign-in is the app's bundle id, not an OAuth
 *        client id. A token minted for any other app fails here, which is the
 *        whole point of checking it.
 *   exp  in the future (firebase/php-jwt enforces exp, nbf and iat).
 *
 * Identity is `sub`: Apple's stable, per-developer-team user id. The email may
 * be a private relay address (@privaterelay.appleid.com) and may be absent
 * after the first sign-in; the name is NEVER in the token -- Apple hands it to
 * the app once, on the first sign-in only, and the app forwards it in the
 * request body. The controller stores it then, because it will not be offered
 * again.
 *
 * The JWKS is cached for a day and refreshed on a key-id miss, so a rotation
 * at Apple costs one extra fetch, not a day of 401s.
 */
final class AppleIdTokenVerifier
{
    public const ISSUER = 'https://appleid.apple.com';

    public const JWKS_URL = 'https://appleid.apple.com/auth/keys';

    private const JWKS_CACHE_KEY = 'apple-id-jwks';

    private const JWKS_TTL_SECONDS = 86400;

    /**
     * @return array{sub: string, email: string|null, name: string|null}|null
     *         null when the token is invalid, expired, or not addressed to us
     */
    public function verify(string $idToken): ?array
    {
        $claims = $this->decode($idToken);
        if ($claims === null) {
            return null;
        }

        if ((string) ($claims['iss'] ?? '') !== self::ISSUER) {
            return null;
        }

        if (! $this->addressedToUs($claims['aud'] ?? '')) {
            return null;
        }

        $sub = trim((string) ($claims['sub'] ?? ''));
        if ($sub === '') {
            return null;
        }

        // Apple sends email_verified as the string "true" or a boolean, and an
        // unverified address must not become an account identity (it could
        // claim another passenger's row by email).
        $verified = filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $email = $verified && isset($claims['email']) && trim((string) $claims['email']) !== ''
            ? trim((string) $claims['email'])
            : null;

        return ['sub' => $sub, 'email' => $email, 'name' => null];
    }

    /**
     * Signature + standard time claims, against Apple's published keys.
     *
     * A key id we do not hold is the one legitimate reason to refetch inside
     * the cache window: Apple rotated, and the token is signed with the new key.
     *
     * @return array<string, mixed>|null
     */
    private function decode(string $idToken): ?array
    {
        $kid = $this->keyId($idToken);
        if ($kid === null) {
            return null;
        }

        $keys = $this->keys();
        if (! isset($keys[$kid])) {
            $keys = $this->keys(refresh: true);
        }
        if (! isset($keys[$kid])) {
            return null;
        }

        try {
            return (array) JWT::decode($idToken, $keys);
        } catch (Throwable) {
            return null;
        }
    }

    /** The `kid` from the token header, without trusting anything else in it. */
    private function keyId(string $idToken): ?string
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return null;
        }
        $header = json_decode(JWT::urlsafeB64Decode($parts[0]) ?: '', true);

        return is_array($header) && isset($header['kid']) && is_string($header['kid']) && $header['kid'] !== ''
            ? $header['kid']
            : null;
    }

    /** @return array<string, \Firebase\JWT\Key> keyed by kid */
    private function keys(bool $refresh = false): array
    {
        if ($refresh) {
            Cache::forget(self::JWKS_CACHE_KEY);
        }

        $jwks = Cache::remember(self::JWKS_CACHE_KEY, self::JWKS_TTL_SECONDS, function (): array {
            try {
                $response = Http::timeout(8)->get(self::JWKS_URL);
            } catch (Throwable) {
                return [];
            }
            $body = $response->successful() ? $response->json() : null;

            return is_array($body) && isset($body['keys']) && is_array($body['keys']) ? $body : [];
        });

        if ($jwks === []) {
            // An empty set is not worth remembering for a day.
            Cache::forget(self::JWKS_CACHE_KEY);

            return [];
        }

        try {
            return JWK::parseKeySet($jwks, 'ES256');
        } catch (Throwable) {
            Cache::forget(self::JWKS_CACHE_KEY);

            return [];
        }
    }

    /**
     * Whether the token's audience is one of the current brand's bundle ids.
     *
     * Fails closed: a brand with no configured bundle ids accepts nothing.
     * `aud` may be a string or, per the JWT spec, an array of them.
     */
    private function addressedToUs(mixed $audience): bool
    {
        $audiences = array_filter(array_map(
            static fn ($a) => is_scalar($a) ? (string) $a : '',
            is_array($audience) ? $audience : [$audience],
        ));
        if ($audiences === []) {
            return false;
        }

        $brand = Context::has('brand') ? (string) Context::get('brand') : null;
        $accepted = (array) config("brands.{$brand}.apple_bundle_ids", []);

        foreach ($accepted as $bundleId) {
            foreach ($audiences as $aud) {
                if (hash_equals((string) $bundleId, $aud)) {
                    return true;
                }
            }
        }

        return false;
    }
}
