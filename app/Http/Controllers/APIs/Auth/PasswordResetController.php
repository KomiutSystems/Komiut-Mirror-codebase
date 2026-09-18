<?php

namespace App\Http\Controllers\APIs\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Super\Access\AccessChangeRecorder;
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

        // Hand the broker the address as STORED. Password::sendResetLink matches
        // with `=`, which is case-sensitive on PostgreSQL, so a person who
        // capitalised their address got the same reassuring "if that email is
        // registered" reply and no email — and no way to tell the difference.
        // The 224 accounts stored with an uppercase letter had it the other way
        // round and could not reset at all.
        $stored = User::byEmail($request->input('email'))->value('email');

        // The answer is the same whatever happens, on purpose: it must not say
        // whether the address is registered, and it must not say whether the
        // mail left. A mailer that cannot deliver (SES refused every address
        // while the account sat in its sandbox; a reset then answered 500
        // "Server Error" on the first morning after cutover) is an incident
        // for us to see in the log, not a status for the person to read.
        try {
            Password::sendResetLink(['email' => $stored ?? (string) $request->input('email')]);
        } catch (\Throwable $e) {
            report($e);
        }

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

        // Same reason as forgot(): the token was issued against the stored
        // spelling, so the reset has to be attempted against it too.
        $data['email'] = User::byEmail($data['email'])->value('email') ?? $data['email'];

        $status = Password::reset($data, function ($user, string $password) {
            $user->password = $password;   // the model's 'hashed' cast hashes it once
            $user->setRememberToken(Str::random(60));
            $user->save();
            event(new PasswordReset($user));

            // Access domain: alert when a privileged account's password is reset.
            if ($user instanceof User) {
                app(AccessChangeRecorder::class)
                    ->recordPrivilegedPasswordReset($user, request()->ip());
            }
        });

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Password reset successful. You can now log in.']);
        }

        return response()->json(['error' => __($status)], 400);
    }
}
