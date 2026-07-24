<?php

namespace App\Http\Controllers\APIs\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * @group Password reset (email)
 *
 * Email-based password reset for dashboard accounts (SACCO admins & staff).
 * Standard token flow: request a link → email → set a new password with the token.
 * The email links to the frontend reset page (App\Notifications\ResetPasswordLink).
 * Passengers on the mobile app reset via phone/SMS instead — see /reset_password.
 */
class PasswordResetController extends Controller
{
    /**
     * Request a reset link
     *
     * Emails a reset link to the account when the email exists. Always returns
     * 200 and the same message — it never reveals whether an email is registered.
     *
     * @unauthenticated
     *
     * @bodyParam email string required The account email. Example: admin@umoja.co.ke
     */
    public function forgot(Request $request)
    {
        Validator::make($request->all(), ['email' => 'required|email'])->validate();

        // Generates + stores a token and sends ResetPasswordLink (frontend URL).
        Password::sendResetLink($request->only('email'));

        return response()->json(['message' => 'If that email is registered, a reset link has been sent.']);
    }

    /**
     * Set a new password
     *
     * Verifies the emailed token and sets the new password.
     *
     * @unauthenticated
     *
     * @bodyParam email string required Example: admin@umoja.co.ke
     * @bodyParam token string required The token from the reset email. Example: 9f8c1a...
     * @bodyParam password string required At least 8 chars, must be confirmed. Example: newsecret1
     * @bodyParam password_confirmation string required Repeat of password. Example: newsecret1
     */
    public function reset(Request $request)
    {
        $data = Validator::make($request->all(), [
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ])->validate();

        $status = Password::reset($data, function ($user, string $password) {
            $user->password = $password;   // the model's 'hashed' cast hashes it once
            $user->setRememberToken(Str::random(60));
            $user->save();
            event(new PasswordReset($user));
        });

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Password reset successful. You can now log in.']);
        }

        return response()->json(['error' => __($status)], 400);
    }
}
