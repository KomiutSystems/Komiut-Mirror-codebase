<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Profile;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Platform\AuditLogger;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * @group Profile
 *
 * Self-service profile edits for the authenticated account. This is the remote
 * save the mobile edit-profile screen needs: today the app persists name and
 * phone to the device only, so those changes never reach the backend.
 *
 * Every field is optional — the client sends just what changed. The account is
 * always the token holder; there is no id in the body to tamper with.
 */
class ProfileUpdateController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Update my profile
     *
     * Sets any of the caller's own name or phone. A passenger who signed in with
     * Google arrives with no phone; this is where they add it. Phone must be
     * unique — it is the account's payment identity for M-Pesa.
     *
     * NOTE: the phone is not verified here. A typo sends a future STK push to a
     * stranger, so an OTP step should gate this before live payments — kept out
     * for now to match the app, which has no verification screen yet.
     *
     * @authenticated
     *
     * @bodyParam firstname string The given name. Example: John
     * @bodyParam lastname string The family name. Example: Mwangi
     * @bodyParam phone string 10-digit Kenyan mobile, unique. Example: 0712345678
     *
     * @response 200 {"success": "Profile updated", "user": {"id": 1, "firstname": "John", "phone": "0712345678"}}
     * @response 422 {"errors": {"phone": ["That phone number is already in use."]}}
     */
    /**
     * Free a number still attached to an account that has never been used
     * socially, so a passenger signing up again can take their own back.
     *
     * NARROW ON PURPOSE. Only a PASSENGER, only one that has never signed in
     * through a provider, and never the caller's own row. A staff or crew
     * number, or one already linked to Google or Apple, is left alone and the
     * unique rule below still rejects it.
     *
     * THIS IS A CLAIM WITHOUT PROOF, and that is a deliberate trade rather than
     * an oversight. Nothing here verifies that the person typing the number owns
     * it, so anyone who knows a dormant passenger's number can take it and
     * become the account it pays from. It is accepted today because the
     * passenger base is 6,541 dormant rows against 2 live social logins - the
     * flow being unusable is a certain cost, the takeover a hypothetical one.
     *
     * THAT CALCULATION INVERTS THE MOMENT PASSENGERS ARE REAL. An OTP on this
     * screen is what makes it safe, and it should land before Google sign-in is
     * promoted at any scale, because this number becomes the account's M-Pesa
     * payment identity.
     *
     * Every release is written to the audit log with both account ids, so a
     * disputed number can be traced and handed back.
     */
    private function releaseFromDormantAccount(string $phone, User $caller): void
    {
        $holder = User::where('phone', $phone)
            ->where('id', '!=', $caller->id)
            ->whereNull('provider')
            // `type` alone is not evidence. The column DEFAULTS to 'passenger'
            // at the database level, so every account created by a path that
            // never set it reads as one: in production 152 such rows carry a
            // sacco_id and 144 are assigned to a vehicle. Those 144 are CREW,
            // who sign in with phone and plate — releasing one locks a driver
            // out of the bus at the stage. Belonging to a SACCO, holding a role
            // or being on a vehicle each disqualify a row from being a dormant
            // passenger, whatever `type` claims.
            ->whereNull('sacco_id')
            ->whereDoesntHave('roles')
            ->whereDoesntHave('vehicle_users')
            ->first();

        if ($holder === null || ! $holder->isPassenger()) {
            return;
        }

        DB::transaction(function () use ($holder, $caller, $phone): void {
            $holder->forceFill(['phone' => null])->save();

            AuditLogger::record(
                action: 'passenger.phone_released',
                data: [
                    'phone_last4' => substr($phone, -4),
                    'released_from_user_id' => $holder->id,
                    'claimed_by_user_id' => $caller->id,
                    'claimed_by_provider' => $caller->provider,
                ],
                subject: ['type' => 'user', 'id' => $holder->id],
            );
        });
    }

    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Accept any form and store the canonical local one, matching register.
        if ($request->filled('phone')) {
            $canonical = Phone::normalise((string) $request->input('phone'));
            if ($canonical === null) {
                return response()->json(['errors' => ['phone' => ['The phone must be a valid Kenyan mobile number.']]], 422);
            }
            $request->merge(['phone' => $canonical]);

            // A returning passenger re-registering through Google types the
            // number they already had. Uniqueness would then 422 them on the
            // phone screen with nowhere to go — the account is new, the number
            // is theirs, and there is no route past it. So a number still held
            // by a DORMANT account is released to them.
            $this->releaseFromDormantAccount($canonical, $user);
        }

        $data = Validator::make($request->all(), [
            'firstname' => 'sometimes|string|max:60',
            'lastname' => 'sometimes|string|max:60',
            'phone' => [
                'sometimes',
                // Unique across everyone except the caller's own current number.
                'unique:users,phone,'.$user->id,
            ],
        ], [
            'phone.unique' => 'That phone number is already in use.',
        ])->validate();

        // fill() over a whitelist so only the sent, permitted keys are touched —
        // an absent field is left as-is rather than nulled.
        $user->fill(array_intersect_key($data, array_flip(['firstname', 'lastname', 'phone'])));
        $user->save();

        return response()->json([
            'success' => 'Profile updated',
            'user' => $user->only(['id', 'firstname', 'lastname', 'email', 'phone']),
        ]);
    }
}
