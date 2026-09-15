<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Passenger;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\FirebaseToken;
use App\Models\User;
use App\Services\Auth\TokenPair;
use App\Services\Platform\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A passenger deletes their own account.
 *
 * Google Play requires it in-app for any app that lets people sign up, and it
 * is right regardless: a person who leaves should be able to take their
 * identity with them. What leaves and what stays is decided by what the data
 * IS:
 *
 *   LEAVES   every field that identifies a person -- names, phone, email,
 *            national id, date of birth, the social-login identity, the
 *            profile image, the password -- and every way back in: Sanctum
 *            tokens, refresh tokens, push-notification device tokens.
 *
 *   STAYS    the money. Bookings, M-Pesa receipts and the loyalty ledger are
 *            the SACCO's financial records and a passenger cannot erase a
 *            transaction they took part in; the rows stay, pointing at a user
 *            row that no longer says who it was. An unspent points balance is
 *            forfeited with the account -- it was a promise to a person who
 *            has asked not to be one here any more.
 *
 * Passengers only. A driver or a SACCO admin is placed on the platform by
 * their SACCO and removed the same way; self-deletion from a phone would let
 * the crew of a bus vanish mid-shift.
 *
 * Confirmed by the caller re-typing their phone number: a tap on the wrong
 * row of a settings screen must not be enough.
 */
final class AccountDeletionController extends Controller
{
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->type !== UserType::Passenger) {
            return response()->json([
                'error' => 'This account is managed by your SACCO. Ask the SACCO office to remove it.',
            ], 422);
        }

        $typed = preg_replace('/\D+/', '', (string) $request->input('phone', ''));
        $own = preg_replace('/\D+/', '', (string) $user->phone);
        if ($typed === '' || substr($typed, -9) !== substr($own, -9)) {
            return response()->json(['error' => 'Type the phone number on this account to confirm.'], 422);
        }

        $userId = (int) $user->id;

        DB::transaction(function () use ($user, $userId): void {
            // Every way back in, first -- so nothing can act as this person
            // between here and the commit.
            $user->tokens()->delete();
            TokenPair::revokeAllFor($user);
            FirebaseToken::where('user_id', $userId)->delete();

            // Then the identity. phone and email are unique columns, so the
            // placeholders carry the id; nothing routes to them.
            User::withoutGlobalScopes()->whereKey($userId)->update([
                'firstname' => 'Deleted',
                'lastname' => 'Account',
                'phone' => 'deleted'.$userId,
                'email' => 'deleted+'.$userId.'@deleted.komiut.invalid',
                'id_number' => null,
                'dob' => null,
                'image' => null,
                'provider' => null,
                'provider_id' => null,
                'password' => bcrypt(Str::random(40)),
                'status' => false,
                'updated_at' => now(),
            ]);
        });

        // On the record, with no PII: the id is enough to answer "when was
        // this account deleted, and by whom" -- the person themselves.
        try {
            AuditLogger::record(
                action: 'passenger.account.deleted',
                data: ['user_id' => $userId],
                actor: ['type' => 'passenger', 'id' => (string) $userId, 'label' => null],
                subject: ['type' => 'user', 'id' => (string) $userId],
            );
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'deleted' => true,
            'message' => 'Your account has been deleted. Your booking and payment history stays with the SACCOs you travelled with, without your name on it.',
        ]);
    }
}
