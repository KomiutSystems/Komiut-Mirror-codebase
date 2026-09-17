<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Auth;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\AppleIdTokenVerifier;
use App\Services\Auth\GoogleIdTokenVerifier;
use App\Services\Auth\TokenPair;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Passenger social sign-in for the mobile apps (Google / Apple).
 *
 * The apps perform the native provider sign-in and send us the resulting access
 * token; we verify it with the provider and mint a Sanctum token. This is
 * PASSENGER-ONLY by hard rule: a driver, admin, or superadmin account can never
 * be created or logged into through social login (see the 403 below).
 *
 * Brand is already resolved by the `brand` middleware before this runs, so the
 * user is looked up / created in the correct per-brand database.
 */
class SocialAuthController extends Controller
{
    private const PROVIDERS = ['google', 'apple'];

    public function handle(Request $request, string $provider): JsonResponse
    {
        if (! in_array($provider, self::PROVIDERS, true)) {
            return response()->json(['error' => 'Unsupported provider'], 422);
        }

        $validator = Validator::make($request->all(), [
            'id_token' => 'required_without:access_token|string',
            'access_token' => 'required_without:id_token|string',
            // Apple only: the name is handed to the app ONCE, on the first
            // sign-in, and never appears in the token. authorization_code is
            // accepted and unused for now (it is what a later revocation
            // check would exchange).
            'given_name' => 'nullable|string|max:100',
            'family_name' => 'nullable|string|max:100',
            'authorization_code' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $identity = $request->filled('id_token')
            ? $this->fromIdToken($provider, (string) $request->input('id_token'))
            : $this->fromAccessToken($provider, (string) $request->input('access_token'));

        if ($identity === null) {
            return response()->json(['error' => 'Could not verify the provider token'], 401);
        }

        [$providerId, $email, $providerName] = $identity;

        // The once-only name from Apple's first sign-in, if the app forwarded it.
        if ($providerName === null) {
            $providerName = trim(implode(' ', array_filter([
                trim((string) $request->input('given_name', '')),
                trim((string) $request->input('family_name', '')),
            ]))) ?: null;
        }

        // Match an already-linked account first, then fall back to email so a
        // passenger who first registered with a password can link social later.
        $user = User::where('provider', $provider)->where('provider_id', $providerId)->first()
            // byEmail, not where('email'): Google and Apple return the address
            // in whatever case the person registered it with, and an exact match
            // on PostgreSQL would miss the stored row and silently create a
            // SECOND account for someone who already has one.
            ?? User::byEmail($email)->first();

        if ($user !== null && ! $user->isPassenger()) {
            // Staff/crew accounts must authenticate with credentials, never social.
            return response()->json(['error' => 'This account cannot sign in with a social provider'], 403);
        }

        if ($user === null) {
            [$firstname, $lastname] = $this->splitName($providerName, $email);

            $user = User::create([
                'firstname' => $firstname,
                'lastname' => $lastname,
                'email' => $email,
                'type' => UserType::Passenger,
                'provider' => $provider,
                'provider_id' => $providerId,
                'status' => true,
            ]);
        } elseif ($user->provider === null) {
            // Existing passenger signing in socially for the first time — link it.
            $user->forceFill(['provider' => $provider, 'provider_id' => $providerId])->save();
        }

        // Same pair every other sign-in issues. A Google passenger is exactly
        // the caller who should never be asked to sign in again on a schedule.
        $tokens = TokenPair::issue($user, TokenPair::nameFor($user));

        return response()->json([
            'user' => $user,
            'access_token' => $tokens['access_token'],
            'token_type' => 'bearer',
            'refresh_token' => $tokens['refresh_token'],
            'expires_at' => $tokens['expires_at'],
            'refresh_expires_at' => $tokens['refresh_expires_at'],
        ]);
    }

    /**
     * The preferred path: a signed ID token whose audience we verify, so a
     * token minted for a different app cannot be replayed here. Google's is
     * checked through its tokeninfo endpoint, Apple's against Apple's JWKS;
     * both verifiers answer the same three facts.
     *
     * @return array{0: string, 1: string|null, 2: string|null}|null
     */
    private function fromIdToken(string $provider, string $idToken): ?array
    {
        $claims = match ($provider) {
            'google' => app(GoogleIdTokenVerifier::class)->verify($idToken),
            'apple' => app(AppleIdTokenVerifier::class)->verify($idToken),
            default => null,
        };

        return $claims === null ? null : [$claims['sub'], $claims['email'], $claims['name']];
    }

    /**
     * Legacy path, kept so the apps can migrate to id_token without a flag day.
     *
     * An access token identifies the user but NOT the app it was issued to, so
     * this path cannot detect a token minted elsewhere. Remove it once both
     * apps send id_token.
     *
     * @return array{0: string, 1: string|null, 2: string|null}|null
     */
    private function fromAccessToken(string $provider, string $accessToken): ?array
    {
        try {
            $providerUser = Socialite::driver($provider)->stateless()->userFromToken($accessToken);
        } catch (Throwable) {
            return null;
        }

        return [(string) $providerUser->getId(), $providerUser->getEmail(), $providerUser->getName()];
    }

    /**
     * Providers hand back a single display name (and Apple often omits it on
     * repeat sign-ins). Split into the first/last the schema expects, falling
     * back to the email local-part so the NOT-fillable pieces still get a value.
     *
     * @return array{0: string, 1: string}
     */
    private function splitName(?string $name, ?string $email): array
    {
        $name = trim((string) $name);

        if ($name === '') {
            $name = $email !== null ? explode('@', $email)[0] : 'Passenger';
        }

        $parts = preg_split('/\s+/', $name, 2) ?: [$name];

        return [$parts[0], $parts[1] ?? ''];
    }
}
