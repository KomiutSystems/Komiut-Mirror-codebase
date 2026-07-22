<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Auth;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\User;
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
            'access_token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        try {
            $providerUser = Socialite::driver($provider)->stateless()->userFromToken($request->input('access_token'));
        } catch (Throwable $e) {
            return response()->json(['error' => 'Could not verify the provider token'], 401);
        }

        $providerId = (string) $providerUser->getId();
        $email = $providerUser->getEmail();

        // Match an already-linked account first, then fall back to email so a
        // passenger who first registered with a password can link social later.
        $user = User::where('provider', $provider)->where('provider_id', $providerId)->first()
            ?? ($email !== null ? User::where('email', $email)->first() : null);

        if ($user !== null && ! $user->isPassenger()) {
            // Staff/crew accounts must authenticate with credentials, never social.
            return response()->json(['error' => 'This account cannot sign in with a social provider'], 403);
        }

        if ($user === null) {
            [$firstname, $lastname] = $this->splitName($providerUser->getName(), $email);

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

        $token = $user->createToken($user->firstname . '-AuthToken')->plainTextToken;

        return response()->json([
            'user' => $user,
            'access_token' => $token,
            'token_type' => 'bearer',
        ]);
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
