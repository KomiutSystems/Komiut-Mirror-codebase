<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\CrewSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A plate (crew) session may call only what the crew app calls.
 *
 * Deny by default: a list of money routes to refuse is always one route
 * short -- the CSV twin of a guarded list, a dashboard tile, a stats card.
 * The list below is the crew screens of the mobile app (its ApiEndpoints),
 * so the app in drivers' hands keeps working, and everything else answers 403
 * for a plate session. The money among these (summaries, transactions) is
 * narrowed to the session's own bus by ScopesToOwnedVehicles.
 *
 * Registered on the whole `api` group; it costs nothing without a bearer
 * token, and with one it resolves the same Sanctum guard auth:sanctum uses.
 */
class CrewSessionLimits
{
    private const CREW_ROUTES = '#(^|/)('
        .'driver/.+|summaries|transactions(/(mpesa|cash))?|payments/history'
        .'|queues/(join|exit|geofence)|trips/(start|bookings)|book_a_ride/.+'
        .'|qrcode/(stk/push|resolve|redeem_points|vehicle(/.*)?)|mpesa/stk(/.*)?'
        .'|notifications(/.*)?|profile/.+|user|account|logout|refresh'
        .'|carbon-credits(/.*)?|bookings/passengers(/.*)?|broadcasting/auth'
        // Signing in again (or as someone else) with a stale token still on
        // the request must never be refused.
        .'|mobile/login|driver/login|login|register|social/[a-z]+|forgot-password|reset-password|verify-otp'
        .')$#';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() === null) {
            return $next($request);
        }

        if (CrewSession::is($request->user('sanctum')) && preg_match(self::CREW_ROUTES, $request->path()) !== 1) {
            return response()->json([
                'error' => 'Sign in with your password to see this. A number-plate sign-in is for the bus crew.',
                'reason' => 'crew_session',
            ], 403);
        }

        return $next($request);
    }
}
