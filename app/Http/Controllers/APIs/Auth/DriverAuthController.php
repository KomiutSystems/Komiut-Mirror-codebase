<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Auth;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Driver\VehicleAssignment;
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
 * Session lifetime follows the SACCO's rotation policy:
 *   - rotates_drivers = true  → token expires at end of day, forcing tomorrow's
 *     driver to re-authenticate against the current assignment.
 *   - rotates_drivers = false → non-expiring token; the fixed driver need not
 *     log in daily.
 *
 * Brand is already resolved by the `brand` middleware, so the vehicle is looked
 * up in the correct per-brand database.
 */
class DriverAuthController extends Controller
{
    public function login(Request $request, VehicleAssignment $assignments, DriverLoginBurst $loginBurst): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string',
            'plate' => 'required|string',
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
        if ($driver === null) {
            $loginBurst->recordFailure($plate, $phone, $ip);

            return response()->json(['error' => 'No active assignment for this phone and vehicle'], 401);
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

            return response()->json(['error' => 'Vehicle not found'], 401);
        }

        if (! $this->sameSacco($driver, $vehicle)) {
            $loginBurst->recordFailure($plate, $phone, $ip, (int) $vehicle->id, $vehicle->sacco_id !== null ? (int) $vehicle->sacco_id : null);

            return response()->json(['error' => 'This vehicle belongs to another SACCO'], 403);
        }

        $assignments->assign($driver, $vehicle);

        // A success on this plate resets the burst counter and, if a burst just
        // preceded it, raises driver.login.suspicious_success.
        $loginBurst->noteSuccess($plate, (int) $driver->id, $ip);

        $expiresAt = optional($vehicle->sacco)->rotates_drivers ? Carbon::now()->endOfDay() : null;

        $token = $driver->createToken('driver-'.$vehicle->plate, ['*'], $expiresAt)->plainTextToken;

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
