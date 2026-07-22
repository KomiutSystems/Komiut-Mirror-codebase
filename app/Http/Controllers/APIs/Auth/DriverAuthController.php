<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Auth;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Models\VehicleUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * Daily driver check-in.
 *
 * A driver is created by their SACCO and does not self-register. They sign in
 * with their phone number and the number plate of the vehicle they are running
 * that day; we confirm an active driver↔vehicle assignment exists.
 *
 * Session lifetime follows the SACCO's rotation policy:
 *   - rotates_drivers = true  → token expires at end of day, forcing tomorrow's
 *     driver to re-authenticate against the current assignment.
 *   - rotates_drivers = false → non-expiring token; the fixed driver need not
 *     log in daily.
 *
 * Brand is already resolved by the `brand` middleware, so the vehicle and
 * assignment are looked up in the correct per-brand database.
 */
class DriverAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string',
            'plate' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $vehicle = Vehicle::with('sacco')->where('plate', $request->plate)->first();

        if ($vehicle === null) {
            return response()->json(['error' => 'Vehicle not found'], 401);
        }

        // An active assignment linking a driver with this phone to this vehicle.
        $assignment = VehicleUser::with('user')
            ->where('vehicle_id', $vehicle->id)
            ->where('status', true)
            ->whereNull('end_date')
            ->whereHas('user', fn ($q) => $q->where('phone', $request->phone))
            ->first();

        if ($assignment === null || $assignment->user === null) {
            return response()->json(['error' => 'No active assignment for this phone and vehicle'], 401);
        }

        $driver = $assignment->user;

        if ($driver->type !== UserType::Driver) {
            return response()->json(['error' => 'This account is not a driver'], 403);
        }

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
}
