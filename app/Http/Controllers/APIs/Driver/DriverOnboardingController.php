<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Driver;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Driver\DriverOnboarding;
use App\Services\Driver\PhoneNotOnboardable;
use App\Services\Driver\PlateNotAvailable;
use App\Services\Super\Fraud\OnboardingVelocity;
use Illuminate\Database\UniqueConstraintViolationException;
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
     * @bodyParam bank_opt_in boolean Deprecated. Onboarding is an NCBA drive, so this now defaults to TRUE and the form no longer asks. Send false only to suppress the lead. Example: true
     * @bodyParam preferred_branch string required The branch the driver would use. Example: Thika Road
     * @bodyParam account_number string The driver's existing bank account number, if they already have one. Example: 1234567890
     * @bodyParam bank_consent boolean Whether the driver consented to their details being shared with the bank. Example: true
     * @bodyParam consent_text_version string Version of the disclosure they were read. Example: 2026-08-a
     * @bodyParam agent_identifier string Who collected the consent. Example: nate.m
     *
     * @response 201 {"driver": {"id": 9, "firstname": "Peter", "lastname": "Kamau", "phone": "0722000111", "type": "driver"}, "sacco": {"id": 4, "name": "Nicco SACCO"}, "vehicle": {"id": 12, "plate": "KDQ446R"}, "next_step": "Sign in with this phone number and number plate. No password needed."}
     * @response 400 {"errors": {"phone": ["The phone field must be 10 digits."]}}
     * @response 409 {"error": "The number plate KDQ446R is already registered."}
     * @response 409 {"error": "This phone number is already registered to a driver at another SACCO. That SACCO can release them from the Crew screen, and then this sign-up will go through."}
     * @response 422 {"errors": {"email": ["This email is already registered."]}}
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
            // At least one letter or digit. `required` alone accepts "-", which
            // normalises to the empty string and would register a vehicle with a
            // blank plate — the row every other punctuation-only plate then
            // resolves to.
            'plate' => 'required|string|max:20|regex:/[A-Za-z0-9]/',
            'vehicle_capacity' => 'nullable|integer|min:1|max:100',
            // The opt-in question is gone from the form: onboarding is now an
            // NCBA drive, so every driver we sign up is assumed to want the
            // account and is simply asked which branch suits them. `bank_opt_in`
            // is still ACCEPTED so an older client keeps working, but it is no
            // longer how the lead gets created — see the default below.
            'bank_opt_in' => 'sometimes|boolean',
            // Consequently required, not conditional. It is the one field the
            // bank cannot proceed without, and the reason Lincoln's lead reached
            // NCBA with a blank branch.
            'preferred_branch' => 'required|string|max:120',
            // NCBA lets a driver open an account from their own phone, so by the
            // time the agent is standing with them they often already have one.
            // Optional: a driver without an account is still a lead, which is
            // the whole point of the list.
            'account_number' => 'nullable|string|max:40',
            // The consent tick. There is nowhere to sign at a matatu stage, so
            // this is what stands in for a signature before the driver's name,
            // phone and ID go to a bank.
            'bank_consent' => 'sometimes|boolean',
            // WHICH disclosure they agreed to. The wording will change, and an
            // old consent must still say what it covered.
            'consent_text_version' => 'nullable|string|max:40',
            // WHO attested. This endpoint is unauthenticated -- the driver has
            // no account yet -- so there is no $request->user() to fall back on
            // and a consent record with no collector names nobody.
            'agent_identifier' => 'nullable|string|max:80',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        try {
            // The origin is taken from the request, never the body: a client
            // that could name its own IP would make the corroboration worthless.
            $driver = $onboarding->onboard(
                $validator->validated() + ['consent_ip' => $request->ip()]
            );
        } catch (PlateNotAvailable $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        } catch (PhoneNotOnboardable $e) {
            // The phone belongs to an account this public flow may not rewrite.
            // Same shape as PlateNotAvailable: the agent did nothing wrong, so
            // the message names who can resolve it rather than blaming the form.
            return response()->json(['error' => $e->getMessage()], 409);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 400);
        } catch (UniqueConstraintViolationException $e) {
            // A duplicate email or phone reached the INSERT and came back as a
            // raw 500. The agent is standing next to the driver with no idea
            // what went wrong, so they retry and get the same crash — which is
            // exactly what happened twice on 10 Aug with an email that already
            // existed. Name the field instead so it can be fixed on the spot.
            //
            // Validation cannot prevent this on its own: `unique` is a SELECT a
            // moment before the INSERT, so two agents submitting the same driver
            // at once still collide. The constraint is the real guard; this
            // turns it into an answer.
            return response()->json([
                'errors' => [$this->duplicateField($e) => ['This '.$this->duplicateField($e).' is already registered.']],
            ], 422);
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

    /**
     * Which column the database rejected, read off the constraint name.
     *
     * Postgres reports `users_email_unique` / `users_phone_unique`, and the
     * message is the only place that detail survives, so it is parsed rather
     * than guessed. Falls back to email, which is the collision that actually
     * happens in the field.
     */
    private function duplicateField(UniqueConstraintViolationException $e): string
    {
        $message = $e->getMessage();

        foreach (['email', 'phone', 'id_number', 'plate'] as $field) {
            if (str_contains($message, '_'.$field.'_unique') || str_contains($message, '('.$field.')')) {
                return $field;
            }
        }

        return 'email';
    }
}
