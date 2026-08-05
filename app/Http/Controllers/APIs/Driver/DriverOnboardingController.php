<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Driver;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Driver\DriverOnboarding;
use App\Services\Driver\PlateNotAvailable;
use App\Services\Super\Fraud\OnboardingVelocity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * @group Driver — self-onboarding
 *
 * Street sign-up. A marketing agent onboards a matatu driver in person, at the
 * stage, on the agent's phone — so this is the one driver endpoint that runs
 * BEFORE authentication: the driver has no account yet and their SACCO usually
 * has no account either.
 *
 * Everything the driver cannot answer standing beside their matatu is derived
 * server-side. In particular the partner bank comes from the brand, never the
 * request body. There is deliberately no OTP: the driver signs in immediately
 * afterwards with the phone number captured here plus the plate on the vehicle.
 */
class DriverOnboardingController extends Controller
{
    /**
     * Onboard a driver
     *
     * Creates the driver's account, registers the vehicle if the SACCO never
     * has, and opens the driver↔vehicle assignment they will log in against.
     * Submitting a `sacco_name` we have not seen adds it to the SACCO directory
     * for review rather than rejecting it — the SACCO is signed up later.
     *
     * Re-onboarding a phone we already know updates that driver and moves them
     * to the new vehicle; it never creates a second account.
     *
     * @unauthenticated
     *
     * @bodyParam firstname string required The driver's first name. Example: Peter
     * @bodyParam lastname string required The driver's surname. Example: Kamau
     * @bodyParam phone string required 10-digit phone number; this is how they sign in. Example: 0722000111
     * @bodyParam email string Optional email address. Example: peter@example.com
     * @bodyParam id_number string required National ID number. Example: 24567890
     * @bodyParam sacco_id integer The SACCO picked from saccos/directory. Required without sacco_name. Example: 4
     * @bodyParam sacco_name string The SACCO typed in when it is not in the directory. Required without sacco_id. Example: Nicco SACCO
     * @bodyParam plate string required The vehicle's number plate; this is the other half of their login. Example: KDQ446R
     * @bodyParam vehicle_capacity integer Seat count of the matatu. Example: 14
     * @bodyParam bank_opt_in boolean Whether the driver wants a partner bank account. Example: true
     * @bodyParam preferred_branch string Branch the driver would use. Required when bank_opt_in is true. Example: Thika Road
     *
     * @response 201 {"driver": {"id": 9, "firstname": "Peter", "lastname": "Kamau", "phone": "0722000111", "type": "driver"}, "sacco": {"id": 4, "name": "Nicco SACCO"}, "vehicle": {"id": 12, "plate": "KDQ446R"}, "next_step": "Sign in with this phone number and number plate. No password needed."}
     * @response 400 {"errors": {"phone": ["The phone field must be 10 digits."]}}
     * @response 409 {"error": "The number plate KDQ446R is already registered."}
     */
    public function store(Request $request, DriverOnboarding $onboarding, OnboardingVelocity $velocity): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'firstname' => 'required|string|max:60',
            'lastname' => 'required|string|max:60',
            // digits:10 matches AuthController's convention for a Kenyan mobile.
            'phone' => 'required|digits:10',
            'email' => 'nullable|email',
            // The partner bank cannot open an account without it, and it is the
            // only identifier the driver reliably carries.
            'id_number' => 'required|string|max:20',
            'sacco_id' => 'required_without:sacco_name|nullable|integer|exists:saccos,id',
            'sacco_name' => 'required_without:sacco_id|nullable|string|max:120',
            'plate' => 'required|string|max:20',
            'vehicle_capacity' => 'nullable|integer|min:1|max:100',
            'bank_opt_in' => 'boolean',
            'preferred_branch' => 'nullable|required_if:bank_opt_in,true|string|max:120',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        try {
            $driver = $onboarding->onboard($validator->validated());
        } catch (PlateNotAvailable $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 400);
        }

        // Abuse signal: one origin onboarding more than the hourly threshold.
        $velocity->record($request->ip(), $driver->sacco?->id === null ? null : (int) $driver->sacco->id);

        return response()->json($this->payload($driver), 201);
    }

    /**
     * Hand-shaped, not a serialised model: this is a public response and the
     * user/sacco/vehicle rows carry contact and payment details that belong to
     * neither the agent nor the driver.
     *
     * @return array<string, mixed>
     */
    private function payload(User $driver): array
    {
        $vehicle = $driver->getRelation('vehicle');

        return [
            'driver' => [
                'id' => (int) $driver->id,
                'firstname' => $driver->firstname,
                'lastname' => $driver->lastname,
                'phone' => $driver->phone,
                'type' => $driver->type?->value,
            ],
            'sacco' => $driver->sacco === null ? null : [
                'id' => (int) $driver->sacco->id,
                'name' => $driver->sacco->name,
            ],
            'vehicle' => [
                'id' => (int) $vehicle->id,
                'plate' => $vehicle->plate,
            ],
            'next_step' => 'Sign in with this phone number and number plate. No password needed.',
        ];
    }
}
