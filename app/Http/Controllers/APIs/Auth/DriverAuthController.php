<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Auth;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Auth\TokenPair;
use App\Services\Driver\VehicleAssignment;
use App\Services\Platform\AuditLogger;
use App\Services\Super\Fraud\DriverLoginBurst;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * Daily driver check-in.
 *
 * A driver signs in with their phone number and the number plate of the vehicle
 * they are running today — no password, and deliberately no OTP.
 *
 * Signing in IS the assignment. SACCOs rotate drivers between vehicles daily and
 * almost never record it in the dashboard, so requiring the assignment to exist
 * beforehand meant a driver who had swapped matatus simply could not log in.
 * Instead the login writes the rotation: the previous assignment is closed and a
 * new one opened, so "who is driving what" maintains itself and the history is
 * kept (see App\Services\Driver\VehicleAssignment).
 *
 * The security guard is the SACCO. A plate is painted on the side of the vehicle
 * and readable by anyone, so it is not a secret; what it cannot do is move a
 * driver onto another SACCO's fleet — driver and vehicle must share a sacco_id.
 *
 * Session lifetime is a flat 24 hours from sign-in, and signing in revokes the
 * driver's other tokens so only one session is ever live.
 *
 * This replaced a rotation-policy rule that produced two bad outcomes: a
 * rotating SACCO expired tokens at end of day, so a driver starting at 23:50
 * got a ten-minute session, while a non-rotating SACCO issued a token that
 * never expired at all. Single-session revocation covers what the rotation rule
 * was actually for — the next driver signing in ends the previous driver's
 * session, whatever the clock says.
 *
 * Brand is already resolved by the `brand` middleware, so the vehicle is looked
 * up in the correct per-brand database.
 */
class DriverAuthController extends Controller
{
    public function login(Request $request, VehicleAssignment $assignments, DriverLoginBurst $loginBurst): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|max:20',
            // max:20 matches onboarding, and bounds the input: the plate lookup
            // is a function on the column, so it cannot use the unique index —
            // an unbounded string on a public endpoint would drive a sequential
            // scan over the whole fleet. The regex requires at least one letter
            // or digit, since "-" normalises to "" and "" is a live predicate.
            'plate' => 'required|string|max:20|regex:/[A-Za-z0-9]/',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $phone = trim((string) $request->phone);
        $plate = (string) $request->plate;
        $ip = $request->ip();

        $driver = User::where('phone', $phone)->first();

        // Unknown phone and unknown plate are both 401 and both say as little as
        // possible: this endpoint is public and must not confirm who drives here.
        //
        // The one thing they DO say is what to do next. A driver never signs up
        // -- there is no registration, no OTP and no document upload anywhere in
        // this product; an agent or their SACCO puts them on the system and from
        // then on they only ever sign in. So a driver standing at a stage with a
        // failing login has exactly one useful action, and telling them costs
        // nothing: it reveals neither whether the phone is known nor whether the
        // plate is.
        if ($driver === null) {
            $loginBurst->recordFailure($plate, $phone, $ip);

            return response()->json([
                'error' => 'No active assignment for this phone and vehicle. Ask your SACCO to register you on this matatu.',
            ], 401);
        }

        // Checked before anything is written, so a non-driver account never
        // acquires a vehicle assignment as a side effect of a failed login.
        if ($driver->type !== UserType::Driver) {
            $loginBurst->recordFailure($plate, $phone, $ip);

            return response()->json(['error' => 'This account is not a driver'], 403);
        }

        if (! $driver->status) {
            $loginBurst->recordFailure($plate, $phone, $ip);

            return response()->json(['error' => 'This account is not active'], 403);
        }

        $vehicle = $assignments->findByPlate($plate);

        if ($vehicle === null) {
            $loginBurst->recordFailure($plate, $phone, $ip);

            return response()->json([
                'error' => 'No active assignment for this phone and vehicle. Ask your SACCO to register you on this matatu.',
            ], 401);
        }

        if (! $this->sameSacco($driver, $vehicle)) {
            $loginBurst->recordFailure($plate, $phone, $ip, (int) $vehicle->id, $vehicle->sacco_id !== null ? (int) $vehicle->sacco_id : null);

            return response()->json(['error' => 'This vehicle belongs to another SACCO'], 403);
        }

        $assignments->assign($driver, $vehicle);

        // A success on this plate resets the burst counter and, if a burst just
        // preceded it, raises driver.login.suspicious_success.
        $loginBurst->noteSuccess($plate, (int) $driver->id, $ip);

        // A shift is 24 hours from the moment it starts.
        //
        // This used to be endOfDay() for SACCOs that rotate drivers and NULL —
        // i.e. never expires — for everyone else. Both were wrong. A driver
        // signing in at 23:50 got a ten-minute session, and every driver at a
        // non-rotating SACCO got a token that was valid forever.
        $expiresAt = Carbon::now()->addDay();

        // One live session per driver. A driver holds one vehicle at a time, so
        // signing in HERE must end any session held elsewhere — a phone left in
        // the previous matatu, a handset that was sold on, or the outgoing
        // driver at a SACCO that rotates. That last case is what
        // `rotates_drivers` was reaching for with endOfDay(), and revoking on
        // sign-in does it properly: the next driver's sign-in ends the previous
        // one regardless of what time it is.
        $driver->tokens()->delete();
        // tokens() only reaches Sanctum PATs. The refresh token lives in its
        // own table, so without this the OUTGOING driver's refresh token would
        // survive the handover and could mint a working access token for a bus
        // they no longer hold -- defeating the whole one-live-session rule.
        TokenPair::revokeAllFor($driver);

        $token = $driver->createToken('driver-'.$vehicle->plate, ['*'], $expiresAt)->plainTextToken;
        // A driver's shift token is deliberately NOT paired with a refresh
        // token. The 24-hour expiry IS the product rule here -- a shift ends --
        // and signing in again is one phone number and a plate, at the stage,
        // which is the shortest login in the system. A 30-day refresh token
        // would quietly undo the session limit the plate check exists to set.

        // A driver signing in IS the vehicle-assignment event: assign() above
        // just moved this bus onto this driver. The SACCO needs that on its
        // activity log — who took which vehicle out, when, and from where —
        // because it is the only record that the handover happened.
        //
        // No phone number in the payload: AuditLogger's contract is ids, counts
        // and refs, never PII. The driver id identifies them; the phone would
        // just put a personal number in a table many roles can read.
        AuditLogger::record(
            action: 'driver.login.succeeded',
            data: [
                'plate' => $vehicle->plate,
                'vehicle_id' => (int) $vehicle->id,
                'driver_id' => (int) $driver->id,
                // A session that dies at midnight means this SACCO rotates its
                // drivers; worth seeing on the log next to the sign-in.
                'session_expires_at' => optional($expiresAt)->toIso8601String(),
            ],
            actor: [
                'type' => 'driver',
                'id' => (string) $driver->id,
                'label' => trim(($driver->firstname ?? '').' '.($driver->lastname ?? '')) ?: null,
            ],
            subject: ['type' => 'vehicle', 'id' => (string) $vehicle->id],
            saccoId: $vehicle->sacco_id !== null ? (int) $vehicle->sacco_id : null,
        );

        return response()->json([
            'user' => $driver,
            'vehicle' => $vehicle,
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * The one thing a publicly-visible plate must not buy: a driver claiming a
     * vehicle outside their own SACCO. A missing sacco_id on either side fails
     * closed rather than matching null to null.
     */
    private function sameSacco(User $driver, Vehicle $vehicle): bool
    {
        return $driver->sacco_id !== null
            && $vehicle->sacco_id !== null
            && (int) $driver->sacco_id === (int) $vehicle->sacco_id;
    }
}
