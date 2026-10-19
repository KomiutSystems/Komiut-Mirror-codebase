<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Money screens need a session opened with a PASSWORD.
 *
 * The mobile app's one sign-in form (MobileAuthController) takes a phone and
 * either a number plate or a password. A plate is painted on the side of the
 * bus, so a plate session is a CREW session: its token carries only the
 * `crew` ability, and these routes refuse it -- even for an owner-driver who
 * also holds the Investor role. Web, password and older crew tokens carry
 * `*` and pass, so nothing that works today changes.
 */
class StaffSession
{
    public function handle(Request $request, Closure $next): Response
    {
        // Refuses only a session explicitly issued as crew. Everything else --
        // a password session, the web, an older driver token -- carries `*`.
        $token = $request->user()?->currentAccessToken();
        $abilities = $token instanceof PersonalAccessToken ? (array) ($token->abilities ?? []) : [];
        if (in_array('crew', $abilities, true) && ! in_array('*', $abilities, true)) {
            return response()->json([
                'error' => 'Sign in with your password to see this. A number-plate sign-in is for the bus crew.',
                'reason' => 'crew_session',
            ], 403);
        }

        return $next($request);
    }
}
