<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Profile;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
