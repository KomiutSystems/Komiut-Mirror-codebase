<?php

namespace App\Http\Controllers\APIs;

use App\Auth\Roles;
use App\Enums\SaccoClaimStatus;
use App\Enums\UserType;
use App\Http\Controllers\Concerns\ExplainsFailures;
use App\Http\Controllers\Controller;
use App\Jobs\SendSMSJob;
use App\Models\Crew;
use App\Models\FirebaseToken;
use App\Models\Gender;
use App\Models\Sacco;
use App\Models\User;
use App\Models\VehicleUser;
use App\Services\Auth\TokenPair;
use App\Services\Driver\AvailableTermini;
use App\Services\Sacco\SaccoDirectory;
use App\Services\Super\Access\AccessChangeRecorder;
use App\Support\Email;
use App\Support\Phone;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class AuthController extends Controller
{
    /**
     * How long an SMS sign-in password stays usable. Long enough for a driver at
     * a stage on a slow network to receive the message and type it; short enough
     * that an intercepted SMS is not a standing key to the account.
     */
    private const SMS_RESET_TTL_MINUTES = 15;

    /** Resets allowed per phone number per window, regardless of who asks. */
    private const SMS_RESET_MAX_PER_WINDOW = 3;

    private const SMS_RESET_WINDOW_SECONDS = 900;

    use ExplainsFailures;

    public function __construct()
    {
        // `refresh` is excepted deliberately. It is the endpoint you reach BECAUSE
        // your access token expired, so requiring a live one to call it would
        // make it useless in the only situation it exists for. Its credential is
        // the refresh token in the request body, checked in the handler.
        $this->middleware('auth:sanctum', ['except' => ['login', 'register', 'registerSacco', 'resetPassword', 'refresh']]);
    }

    public function register(Request $request)
    {
        // Accept the number in any form (+254…, 254…, 07…, 7…) and store the one
        // canonical local form, so unique/login/lookup all agree. Fails the same
        // 400 shape as the validator when it is not a Kenyan mobile.
        if ($request->filled('phone')) {
            $canonical = Phone::normalise((string) $request->input('phone'));
            if ($canonical === null) {
                return $this->invalidField('phone', 'Enter a valid Kenyan mobile number, for example 0712345678.');
            }
            $request->merge(['phone' => $canonical]);
        }

        // Store the address in ONE case, so `unique` actually means unique.
        // PostgreSQL compares text case-sensitively, so without this
        // `Henry@gmail.com` and `henry@gmail.com` are two different accounts to
        // the unique rule and the same mailbox to the person — and whichever one
        // they created second would then shadow the first at sign-in.
        if ($request->filled('email')) {
            $request->merge(['email' => Email::normalise((string) $request->input('email'))]);
        }

        $validator = Validator::make($request->all(), [
            'firstname' => 'required|string',
            'lastname' => 'required|string',
            'email' => 'required|email|unique:users',
            'phone' => 'required|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'dob' => 'nullable|date|before:today',
            // Gender is no longer collected at sign-up. users.gender_id is nullable;
            // it is still accepted if an older client sends it, but never required.
            'gender' => 'nullable|exists:genders,name',
        ], [
            'firstname.required' => 'Enter your first name.',
            'lastname.required' => 'Enter your last name.',
            'email.required' => 'Enter your email address.',
            'email.email' => "That doesn't look like an email address. Check for a typo.",
            'email.unique' => 'This email is already registered. Sign in instead, or use a different address.',
            'phone.required' => 'Enter your phone number.',
            'phone.unique' => 'This phone number is already registered. Sign in instead, or use a different number.',
            'password.required' => 'Choose a password.',
            'password.min' => 'Your password needs at least 8 characters.',
            'password.confirmed' => "The two passwords don't match.",
            'dob.date' => "That doesn't look like a date.",
            'dob.before' => 'Your date of birth has to be in the past.',
            'gender.exists' => 'Choose one of the listed options.',
        ]);
        if ($validator->fails()) {
            return $this->invalid($validator);
        }

        $user = new User;
        $user->firstname = $request->firstname;
        $user->lastname = $request->lastname;
        $user->email = $request->email;
        $user->password = $request->password; // hashed once by the model's 'hashed' cast
        $user->phone = $request->phone;
        if ($request->filled('dob')) {
            $user->dob = Carbon::parse($request->dob);
        }
        if ($request->filled('gender')) {
            $user->gender_id = optional(Gender::where('name', $request->gender)->first())->id;
        }
        if ($user->save()) {
            $role = Role::where('name', 'User')->first();
            if ($role == null) {
                $role = Role::create(['name' => 'User']);
            }
            $user->assignRole($role);
            $credentials = request(['email', 'password']);
            if (! Auth::attempt($credentials)) {
                return response()->json([
                    'message' => "We couldn't sign you in. Check your email or phone number and password, then try again.",
                    'error' => "We couldn't sign you in. Check your email or phone number and password, then try again.",
                ], 401);
            }

            $user = Auth::user();
            $token = TokenPair::issue($user, TokenPair::nameFor($user));

            if ($request->firebase_token != '' && $request->device_id != '') {
                $firebaseToken = new FirebaseToken;
                $myToken = FirebaseToken::where('device_id', $request->device_id)->where('user_id', auth()->user()->id)->first();
                if ($myToken != null) {
                    $firebaseToken = $myToken;
                }
                $firebaseToken->device_id = $request->device_id;
                $firebaseToken->user_id = auth()->user()->id;
                $firebaseToken->firebase_token = $request->firebase_token;
                $firebaseToken->save();
            }
        }

        return $this->respondWithToken($token, null);
    }

    /**
     * Register a SACCO
     *
     * Self-service SACCO onboarding: creates the SACCO and its first admin
     * account in one atomic step, then signs them in. The SACCO's **name** and
     * **email** are the identity — no personal names are collected. The admin
     * logs in afterwards with this same email + password. Sensitive setup
     * (M-Pesa credentials, billing plan, vehicles, routes) happens later inside
     * the dashboard.
     *
     * @unauthenticated
     *
     * @bodyParam name string required The SACCO's name (unique). Example: Umoja SACCO
     * @bodyParam email string required Contact + login email (unique). Example: admin@umoja.co.ke
     * @bodyParam phone string required 10-digit phone number. Example: 0700111222
     * @bodyParam password string required At least 8 characters, must be confirmed. Example: secret123
     * @bodyParam password_confirmation string required Repeat of password. Example: secret123
     */
    public function registerSacco(Request $request)
    {
        // Accept the number in any Kenyan form (+254..., 254..., 07..., 7...) and
        // store the one canonical local form. This path used to demand exactly
        // ten digits while the passenger register beside it accepted anything
        // Phone could normalise -- so the same number was fine on one screen and
        // "must be 10 digits" on the other, with nothing to tell the person that
        // dropping the +254 would fix it.
        if ($request->filled('phone')) {
            $canonical = Phone::normalise((string) $request->input('phone'));
            if ($canonical === null) {
                return $this->invalidField('phone', 'Enter a valid Kenyan mobile number, for example 0712345678.');
            }
            $request->merge(['phone' => $canonical]);
        }

        // Store the address in ONE case, so `unique` actually means unique.
        // PostgreSQL compares text case-sensitively, so without this
        // `Henry@gmail.com` and `henry@gmail.com` are two different accounts to
        // the unique rule and the same mailbox to the person — and whichever one
        // they created second would then shadow the first at sign-in.
        if ($request->filled('email')) {
            $request->merge(['email' => Email::normalise((string) $request->input('email'))]);
        }

        $validator = Validator::make($request->all(), [
            // NOT unique:saccos,name — the name may already exist as an unclaimed
            // directory entry that drivers were onboarded under. That case is a
            // CLAIM, not a collision; only an already-claimed name is rejected.
            'name' => 'required|string|max:120',
            'email' => 'required|email|unique:saccos,email|unique:users,email',
            'phone' => 'required|unique:users,phone',
            'password' => 'required|string|min:8|confirmed',
        ], [
            // Every message names the fix, not just the fault. A person stuck on
            // this form can act on all of these without contacting anyone.
            'name.required' => "Enter your SACCO's name.",
            'name.max' => 'That SACCO name is too long. Use 120 characters or fewer.',
            'email.required' => "Enter the email address you'll sign in with.",
            'email.email' => "That doesn't look like an email address. Check for a typo.",
            'email.unique' => 'This email is already registered. Sign in instead, or use a different address.',
            'phone.required' => 'Enter a phone number we can reach you on.',
            'phone.unique' => 'This phone number is already registered. Sign in instead, or use a different number.',
            'password.required' => 'Choose a password.',
            'password.min' => 'Your password needs at least 8 characters.',
            'password.confirmed' => "The two passwords don't match.",
        ]);
        if ($validator->fails()) {
            return $this->invalid($validator);
        }

        $directory = app(SaccoDirectory::class);
        // claimableByName, not unclaimedByName: an unauthenticated caller may
        // only claim a directory stub with nothing attached to it. Claiming keeps
        // the row's id and makes the caller its SACCO Admin, so allowing it on a
        // SACCO that already has users or vehicles handed over a live business —
        // 45 of the 48 claimable rows in production were exactly that.
        $claimable = $directory->claimableByName($request->name);

        if ($claimable === null && $directory->isNameTaken($request->name)) {
            // Two different refusals, because they need two different actions.
            // An unclaimed row with drivers or buses on it has no admin to ask —
            // saying otherwise would read as "the platform lost my account" to
            // the one person who actually runs the place.
            $message = $directory->requiresVerifiedClaim($request->name)
                ? 'We already hold records for this SACCO. To register as its admin we have to verify you are authorised — contact support and we will complete it with you.'
                : 'This SACCO is already registered. Ask its admin to add you.';

            return response()->json([
                'errors' => ['name' => [$message]],
            ], 400);
        }

        $user = DB::transaction(function () use ($request, $directory, $claimable) {
            // Claiming keeps the directory entry's id, so every driver already
            // onboarded under this name becomes a member of the real SACCO.
            // brand is auto-stamped by BelongsToBrand from the active brand context
            $sacco = $claimable !== null
                ? $directory->markClaimed($claimable, $request->email, $request->phone)
                : Sacco::create([
                    'name' => $request->name,
                    'email' => $request->email,
                    'phone' => $request->phone,
                    'status' => 1, // active so the admin can sign in now; set 0 to gate on superadmin approval
                    'claim_status' => SaccoClaimStatus::Claimed,
                    'source' => 'self_registered',
                    'verified_at' => now(),
                ]);

            $user = new User;
            $user->firstname = $request->name; // display name = SACCO name (personal names not collected)
            $user->email = $request->email;
            $user->phone = $request->phone;
            $user->password = $request->password; // hashed once by the model's 'hashed' cast
            $user->sacco_id = $sacco->id;
            $user->type = UserType::Admin;
            $user->status = true;
            $user->save();

            $user->assignRole(Role::firstOrCreate(['name' => Roles::SACCO_ADMIN]));

            return $user;
        });

        Auth::loginUsingId($user->id);
        $token = TokenPair::issue($user, TokenPair::nameFor($user));

        return $this->respondWithToken($token, null);
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required_without:phone',
            'phone' => 'required_without:email',
            'password' => 'required',
        ], [
            // Either identifier is fine, so both rules say the same thing —
            // "the email field is required when phone is not present" describes
            // the rule, not what the person left blank.
            'email.required_without' => 'Enter your email address or phone number.',
            'phone.required_without' => 'Enter your email address or phone number.',
            'password.required' => 'Enter your password.',
        ]);
        if ($validator->fails()) {
            return $this->invalid($validator);
        }

        // Resolve one identifier from the dashboard's combined "email or phone"
        // field. An email must authenticate by email even when the client sends it
        // under `phone` (or under both) — otherwise it is looked up in the phone
        // column, never matches, and every email login fails with 401.
        $email = trim((string) $request->input('email', ''));
        $phone = trim((string) $request->input('phone', ''));
        if ($email === '' && str_contains($phone, '@')) {
            $email = $phone;
            $phone = '';
        }
        $byPhone = $email === '' && $phone !== '';

        // Match the number however it was typed (+254…, 254…, 07…) against
        // however the row was stored (canonical local, or a legacy 254… import).
        $phoneForms = $byPhone ? Phone::lookupForms($phone) : [];

        $crew = null;
        if ($byPhone) {
            // Crew sign in with their own phone + password (a Crew row, not an
            // Auth provider account), so they are checked here directly.
            $crew = Crew::whereIn('phone', $phoneForms)->where('status', true)->first();
            if ($crew !== null && ! Hash::check($request->password, $crew->password)) {
                $crew = null;
            }
            if ($crew !== null) {
                Auth::loginUsingId($crew->user_id);
            }
        }

        if ($crew === null) {
            if ($byPhone) {
                // Resolve to the user's actual stored phone, then attempt exactly.
                $stored = User::whereIn('phone', $phoneForms)->value('phone');
                $credentials = ['phone' => $stored ?? $phone, 'password' => $request->password];
            } else {
                // Resolve to the user's actual stored email, then attempt exactly
                // — the same move the phone branch above makes, and for the same
                // reason. Auth::attempt compares with `=`, which is
                // case-SENSITIVE on PostgreSQL and was case-INSENSITIVE on the
                // legacy MySQL, so `Henry@gmail.com` stopped matching a stored
                // `henry@gmail.com` the day the platform moved to Frankfurt. It
                // failed as a WRONG PASSWORD, which sent people to reset a
                // password that had never been wrong.
                // take(2): if two rows ever matched case-insensitively, picking
                // one arbitrarily could sign someone into the WRONG account.
                // There are none today (verified in production) and normalising
                // on write keeps it that way, but the failure mode is bad enough
                // to be worth a row. On ambiguity, fall back to the exact match —
                // the old behaviour, which is wrong for nobody.
                $matches = User::byEmail($email)->take(2)->pluck('email');
                $stored = $matches->count() === 1 ? $matches->first() : null;

                $credentials = ['email' => $stored ?? $email, 'password' => $request->password];
            }
            if (! Auth::attempt($credentials)) {
                // Second chance: a temporary password issued by auth/reset_password.
                //
                // It lives beside the real password rather than replacing it,
                // which is what stops that public endpoint from being an account
                // lockout for anyone who knows a phone number. So it has to be
                // checked here, and only AFTER the real password has failed —
                // the normal path must not pay for this, and a still-valid
                // temporary password must not be consumed by someone signing in
                // with the password they already had.
                $resetUser = $byPhone
                    ? User::whereIn('phone', $phoneForms)->first()
                    : User::byEmail($email)->first();

                if ($this->consumeSmsResetPassword($resetUser, (string) $request->password)) {
                    Auth::loginUsingId($resetUser->id);
                } else {
                    // Access domain: count failures per admin account; alerts on a burst.
                    app(AccessChangeRecorder::class)
                        ->recordFailedLogin($byPhone ? $phone : $email, $request->ip());

                    return response()->json([
                        'message' => "We couldn't sign you in. Check your email or phone number and password, then try again.",
                        'error' => "We couldn't sign you in. Check your email or phone number and password, then try again.",
                    ], 401);
                }
            }
        }

        if (! Auth::check()) {
            return response()->json([
                    'message' => "We couldn't sign you in. Check your email or phone number and password, then try again.",
                    'error' => "We couldn't sign you in. Check your email or phone number and password, then try again.",
                ], 401);
        }

        $token = null;
        $user = Auth::user();
        // Access domain: flag a dashboard account that signs in with no permissions.
        if ($user instanceof User) {
            app(AccessChangeRecorder::class)->recordDashboardLogin($user);
        }
        $token = TokenPair::issue($user, TokenPair::nameFor($user));
        // \Log::info('User Details:'.json_encode(auth('api')->user()));
        if ($request->firebase_token != '' && $request->device_id != '') {
            $firebaseToken = new FirebaseToken;
            $myToken = FirebaseToken::/* where("device_id", $request->device_id)-> */ where('user_id', auth()->user()->id)->first();
            if ($myToken != null) {
                $firebaseToken = $myToken;
            }
            $firebaseToken->device_id = $request->device_id;
            $firebaseToken->user_id = auth()->user()->id;
            $firebaseToken->firebase_token = $request->firebase_token;
            $firebaseToken->save();
        }

        return $this->respondWithToken($token, $crew);
    }

    public function resetPassword(Request $request)
    {
        $forms = Phone::lookupForms((string) $request->input('phone'));
        if ($forms === []) {
            return $this->invalidField('phone', 'Enter a valid Kenyan mobile number, for example 0712345678.');
        }
        $user = User::whereIn('phone', $forms)->first();
        if ($user == null) {
            // NOTE: this confirms whether a number has an account here, which is an
            // enumeration leak. Kept as-is because changing it changes the
            // contract the apps rely on; flagged for a product decision.
            return response()->json([
                'message' => "We don't have an account with that phone number. Check the number, or register instead.",
                'error' => "We don't have an account with that phone number. Check the number, or register instead.",
            ], 401);
        }
        $phone = Phone::msisdn((string) $request->input('phone'));

        // PER-PHONE, not just per-IP. The route limiter keys on the caller, and
        // the caller is the attacker — a botnet or a phone on a changing mobile
        // IP gets a fresh allowance every time. What has to be protected is the
        // NUMBER being reset, so the limiter keys on that.
        $limiterKey = 'sms-reset:'.$phone;

        if (RateLimiter::tooManyAttempts($limiterKey, self::SMS_RESET_MAX_PER_WINDOW)) {
            return response()->json([
                'message' => 'Too many reset requests for that number. Try again in a few minutes.',
                'error' => 'Too many reset requests for that number. Try again in a few minutes.',
            ], 429);
        }

        RateLimiter::hit($limiterKey, self::SMS_RESET_WINDOW_SECONDS);

        // A staff account is not resettable by SMS. It is the highest-value
        // target on the platform and it already has a verified email route
        // (auth/forgot-password), so there is no reason to expose it to anyone
        // holding a phone number.
        //
        // The RESPONSE is identical to the success case on purpose. Saying "that
        // is a staff account" here would turn this endpoint into an oracle for
        // finding admins to attack. The person who needs to know is the account's
        // real owner, and they are the one holding the phone — so they get told,
        // over SMS, where to go instead.
        if ($this->isStaffAccount($user)) {
            dispatch(new SendSMSJob(
                $phone,
                'Hi '.$user->firstname.'. Password reset by SMS is not available for staff accounts. '
                .'Use the "Forgot password" link on the dashboard sign-in page instead.'
            ));

            app(AccessChangeRecorder::class)
                ->recordPrivilegedPasswordReset($user, $request->ip());

            return response()->json(['success' => 'New Password has been sent to '.$request->phone.'. Use it to login.']);
        }

        // NOT $user->password. This endpoint is public and identifies people by
        // a phone number, which is not a secret, so overwriting the real
        // password let anyone lock any account out of itself at will — the SMS
        // went to the owner, so the attacker gained nothing but the denial.
        //
        // Written beside the real password instead, with an expiry: for the
        // length of the window either one signs you in, and using this one
        // consumes it (see login()). An unrequested reset now costs its victim
        // one confusing SMS rather than their account.
        $password = Str::password(10, symbols: false);

        $user->sms_reset_password = $password;   // hashed by the model's cast
        $user->sms_reset_expires_at = now()->addMinutes(self::SMS_RESET_TTL_MINUTES);

        if ($user->save()) {
            dispatch(new SendSMSJob(
                $phone,
                'Hi '.$user->firstname.'. Use '.$password.' to sign in. It expires in '
                .self::SMS_RESET_TTL_MINUTES.' minutes. Your existing password still works. '
                .'If you did not ask for this, ignore this message.'
            ));

            return response()->json(['success' => 'New Password has been sent to '.$request->phone.'. Use it to login.']);
        } else {
            return response()->json([
                'message' => "We couldn't reset your password just now. Try again in a moment.",
                'error' => "We couldn't reset your password just now. Try again in a moment.",
            ], 401);
        }

    }

    /**
     * Does this account actually carry staff capability?
     *
     * POSITIVE evidence only — an admin/superadmin type, or a role, or a direct
     * permission. Not "anything that is not a passenger or a driver": `type` is
     * one nullable column, and reading a missing value as privilege would
     * quietly withdraw SMS reset from ordinary accounts whose type was never
     * stamped, with nothing in the response to explain why.
     *
     * Three signals rather than one because they fail in different directions. A
     * SACCO admin whose `type` was never updated is still a SACCO admin, and the
     * question is "would losing this account matter", not "what does one column
     * say".
     */
    private function isStaffAccount(User $user): bool
    {
        return in_array($user->type, [UserType::Admin, UserType::Superadmin], true)
            || $user->roles()->exists()
            || $user->permissions()->exists();
    }

    /**
     * A temporary SMS password that is still valid, checked in constant time.
     *
     * Consumed on use: a single-use password that survives its first use is a
     * standing second credential on the account.
     */
    private function consumeSmsResetPassword(?User $user, string $candidate): bool
    {
        if ($user === null
            || blank($user->sms_reset_password)
            || $user->sms_reset_expires_at === null
            || $user->sms_reset_expires_at->isPast()) {
            return false;
        }

        if (! Hash::check($candidate, $user->sms_reset_password)) {
            return false;
        }

        $user->forceFill([
            'sms_reset_password' => null,
            'sms_reset_expires_at' => null,
        ])->save();

        return true;
    }

    /**
     * Get the authenticated User.
     *
     * @return JsonResponse
     */
    public function user(Request $request)
    {
        $crew = null;
        if ($request->crew_id > 0) {
            // Only ever the caller's OWN crew record — a raw find($request->crew_id)
            // let any authenticated user read another crew's phone/email/id_number.
            $crew = Crew::where('id', $request->crew_id)
                ->where('user_id', auth()->id())
                ->first();
        }

        return response()->json([
            'user' => User::with(['roles', 'gender', 'sacco'])->where('id', auth()->user()->id)->first(),
            'permissions' => auth()->user()->getAllPermissions()->pluck('name'),
            'vehicle_users' => VehicleUser::with('vehicle.seat', 'vehicle.sacco')->where('user_id', auth()->user()->id)
                ->where('status', true)->get(),
            // NOT terminus_users directly. That table is never written by this
            // system -- 450 crew assignments in Frankfurt and zero terminus
            // rows -- and this list is the driver app's ONLY source for its
            // terminus picker, so every driver would have opened it empty and
            // been unable to join a queue at all. See AvailableTermini.
            'termini' => app(AvailableTermini::class)->forDriver(auth()->user()),
            'sacco' => Sacco::where('id', auth()->user()->sacco_id)->first(),
            'crew' => $crew,
        ]);
    }

    /**
     * Log the user out (Invalidate the token).
     *
     * @return JsonResponse
     */
    public function logout()
    {
        // auth()->logout();
        FirebaseToken::where('user_id', auth()->user()->id)->delete();
        auth()->user()->tokens()->delete();
        // tokens() only reaches Sanctum PATs. Without this the refresh token
        // would survive logout and could mint a fresh access token afterwards.
        TokenPair::revokeAllFor(auth()->user());

        return response()->json(['message' => 'Successfully logged out']);
    }

    /**
     * Rotate the caller's access token.
     *
     * Sanctum is not JWT: there is no `auth()->refresh()` on the sanctum
     * guard, so the previous implementation threw a 500 (BadMethodCallException)
     * on every call. A Sanctum "refresh" is a rotation — mint a NEW personal
     * access token using the same policy the app's other logins use (see
     * login/register), then revoke the token this request arrived on so the
     * old bearer stops working immediately.
     *
     * @return JsonResponse
     */
    /**
     * Exchange a refresh token for a new access/refresh pair.
     *
     * Unauthenticated by design — see the constructor. The credential is the
     * refresh token itself, and the access token that sent the caller here is
     * expected to be dead.
     *
     * Rotation is single-use: the presented token is spent, and a replay of an
     * already-spent one revokes every refresh token the account holds, because
     * the only innocent explanation is a client retrying a request whose
     * response it lost — and that client can simply log in again.
     *
     * @unauthenticated
     *
     * @bodyParam refresh_token string required The refresh token issued at login. Example: 3f9a...
     */
    public function refresh(Request $request)
    {
        $presented = (string) ($request->input('refresh_token') ?? '');

        $rotated = TokenPair::rotate($presented);

        if ($rotated === null) {
            // One message for unknown, expired and already-spent. Telling them
            // apart would let someone sort real stolen strings from junk.
            return response()->json([
                'error' => 'That session has expired. Please sign in again.',
            ], 401);
        }

        // respondWithToken reads auth()->user(); nothing authenticated this
        // request, so establish the identity the refresh token just proved.
        Auth::setUser($rotated['user']);

        return $this->respondWithToken($rotated['tokens'], null);
    }

    /**
     * Get the token array structure.
     *
     * @param  string  $token
     * @return JsonResponse
     */
    /**
     * @param  array{access_token: string, refresh_token: string, expires_at: string|null, refresh_expires_at: string}  $tokens
     */
    protected function respondWithToken(array $tokens, $crew)
    {
        return response()->json([
            'user' => User::where('id', auth()->user()->id)->with(['gender', 'roles'])->first(),
            'crew' => $crew,
            'permissions' => auth()->user()->getAllPermissions()->pluck('name'),
            'vehicle_users' => VehicleUser::with('vehicle.seat', 'vehicle.sacco')->where('user_id', auth()->user()->id)
                ->where('status', true)->get(),
            // NOT terminus_users directly. That table is never written by this
            // system -- 450 crew assignments in Frankfurt and zero terminus
            // rows -- and this list is the driver app's ONLY source for its
            // terminus picker, so every driver would have opened it empty and
            // been unable to join a queue at all. See AvailableTermini.
            'termini' => app(AvailableTermini::class)->forDriver(auth()->user()),
            'sacco' => Sacco::where('id', auth()->user()->sacco_id)->first(),
            // `access_token` and `token_type` keep their old names and meaning,
            // so every existing client is unaffected. The three keys after them
            // are additive: a client that ignores them behaves exactly as it did
            // before, and one that uses them stops needing a daily login.
            'access_token' => $tokens['access_token'],
            'token_type' => 'bearer',
            'refresh_token' => $tokens['refresh_token'],
            'expires_at' => $tokens['expires_at'],
            'refresh_expires_at' => $tokens['refresh_expires_at'],
        ]);
    }

    public function generateRandomAlphabets($length)
    {
        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $randomString = '';

        for ($i = 0; $i < $length; $i++) {
            $index = rand(0, strlen($characters) - 1);
            $randomString .= $characters[$index];
        }

        return $randomString;
    }
}
