<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Auth;

use App\Auth\Roles;
use App\Enums\UserType;
use App\Http\Controllers\APIs\AuthController;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Sql\PlateSql;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * @group Auth
 *
 * The mobile app's one sign-in form for everyone who is not a passenger:
 * a phone number, and EITHER the bus's number plate OR a password.
 *
 *   plate     the bus crew (driver, conductor) -- exactly driver/login, but
 *             the session can only reach crew screens: a plate is painted on
 *             the bus, so it must never open a money screen (StaffSession).
 *   password  SACCO staff and investors (stage manager = Queue Supervisor,
 *             Investor, SACCO admin...) -- the same account and password they
 *             use on the web, signed in through AuthController::login.
 *
 * The plate is tried first, and only when the second field looks like a plate
 * and the phone belongs to a crew account; anything else is a password. Every
 * failure answers the same way, so the form never says which half was wrong.
 */
class MobileAuthController extends Controller
{
    /**
     * Roles that have screens in the mobile app, and the key the app routes on.
     * An account with none of these (a passenger, a support agent) is refused
     * here with a pointer to the right sign-in.
     */
    public const MOBILE_STAFF_ROLES = [
        Roles::QUEUE_SUPERVISOR => 'stage_manager',
        Roles::INVESTOR => 'investor',
        Roles::SACCO_ADMIN => 'sacco_admin',
        Roles::OPERATIONS_MANAGER => 'operations_manager',
        Roles::FLEET_MANAGER => 'fleet_manager',
        Roles::FINANCE => 'finance',
        Roles::CASHLESS_ADMIN => 'cashless_admin',
        Roles::SUPER_ADMIN => 'super_admin',
    ];

    private const FAILED = "We couldn't sign you in. Check your phone number and your number plate or password, then try again.";

    /**
     * Sign in with phone + plate (crew) or phone + password (staff, investors)
     *
     * @unauthenticated
     *
     * @bodyParam phone string required The account's phone number, any form (07…, 254…, +254…). Example: 0712345678
     * @bodyParam secret string required The bus's number plate (crew) or the account password (staff). Example: KDA 123A
     * @bodyParam firebase_token string Push token, as on the other sign-ins.
     * @bodyParam device_id string Device id, as on the other sign-ins.
     *
     * @response 200 {"session": "crew", "...": "driver/login's body"}
     * @response 200 {"session": "staff", "staff_roles": ["stage_manager"], "...": "login's body: access_token, refresh_token, user, permissions, sacco"}
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|max:20',
            'secret' => 'required|string|max:255',
        ], [
            'phone.required' => 'Enter your phone number.',
            'secret.required' => 'Enter your number plate or your password.',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $phone = trim((string) $request->input('phone'));
        $secret = (string) $request->input('secret');
        $fallback = null;
        $forms = Phone::lookupForms($phone);

        if ($forms !== [] && $this->looksLikePlate($secret)
            && User::whereIn('phone', $forms)->where('type', UserType::Driver->value)->exists()) {
            $crew = $this->asCrew($request, $phone, $secret);
            if ($crew->getStatusCode() === 200) {
                return $crew;
            }
            // A crew member whose password happens to look like a plate still
            // gets the password check below, before the failure is reported.
            $fallback = $crew;
        }

        $user = $forms === [] ? null : User::whereIn('phone', $forms)->first();
        if ($user === null || ! $user->status || ! filled($user->password) || ! Hash::check($secret, (string) $user->password)) {
            return $fallback ?? response()->json(['error' => self::FAILED, 'message' => self::FAILED], 401);
        }

        $staffRoles = array_values(array_intersect_key(self::MOBILE_STAFF_ROLES, array_flip($user->getRoleNames()->all())));
        if ($staffRoles === []) {
            return response()->json([
                'error' => $user->type === UserType::Driver
                    ? 'Crew sign in with the number plate of their bus.'
                    : 'This account has no screens in the app yet. Passengers sign in with Google or Apple; staff can use the web dashboard.',
                'reason' => 'no_mobile_role',
            ], 403);
        }

        return $this->asStaff($request, (string) $user->phone, $secret, $staffRoles);
    }

    private function looksLikePlate(string $secret): bool
    {
        return preg_match('/^[A-Z]{2,4}\d{1,4}[A-Z]{0,2}$/', PlateSql::normalise($secret)) === 1;
    }

    /** driver/login, with a session limited to crew screens. */
    private function asCrew(Request $request, string $phone, string $plate): JsonResponse
    {
        $sub = Request::create($request->getRequestUri(), 'POST', ['phone' => $phone, 'plate' => $plate], [], [], $request->server->all());
        $sub->attributes->set('token_abilities', ['crew']);

        /** @var JsonResponse $res */
        $res = app()->call([app(DriverAuthController::class), 'login'], ['request' => $sub]);

        return $res->getStatusCode() === 200 ? $this->tag($res, ['session' => 'crew']) : $res;
    }

    /** AuthController::login: the same account and password as the web. */
    private function asStaff(Request $request, string $phone, string $password, array $staffRoles): JsonResponse
    {
        $sub = Request::create($request->getRequestUri(), 'POST', array_filter([
            'phone' => $phone,
            'password' => $password,
            'firebase_token' => $request->input('firebase_token'),
            'device_id' => $request->input('device_id'),
        ], fn ($v) => $v !== null), [], [], $request->server->all());

        /** @var JsonResponse $res */
        $res = app(AuthController::class)->login($sub);

        return $res->getStatusCode() === 200 ? $this->tag($res, ['session' => 'staff', 'staff_roles' => $staffRoles]) : $res;
    }

    /** @param  array<string, mixed>  $extra */
    private function tag(JsonResponse $res, array $extra): JsonResponse
    {
        return $res->setData($extra + (array) $res->getData(true));
    }
}
