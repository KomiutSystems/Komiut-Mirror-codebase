<?php

namespace App\Http\Controllers\APIs;

use App\Auth\Roles;
use App\Enums\SaccoClaimStatus;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Services\Sacco\SaccoDirectory;
use App\Jobs\SendSMSJob;
use App\Models\FirebaseToken;
use App\Models\Gender;
use App\Models\Sacco;
use App\Models\TerminusUser;
use App\Models\User;
use App\Models\VehicleUser;
use App\Models\Crew;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;

class AuthController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum', ['except' => ['login', 'register', 'registerSacco', 'resetPassword']]);
    }
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'firstname' => 'required|string',
            'lastname' => 'required|string',
            'email' => 'required|email|unique:users',
            'phone' => 'required|digits:10|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'dob' => 'nullable|date|before:today',
            // Gender is no longer collected at sign-up. users.gender_id is nullable;
            // it is still accepted if an older client sends it, but never required.
            'gender' => 'nullable|exists:genders,name',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
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
            if (!Auth::attempt($credentials)) {
                return response()->json(['error' => 'Invalid username/password'], 401);
            }

            $user = Auth::user();
            $token = $user->createToken($user->firstname . '-AuthToken')->plainTextToken;

            if ($request->firebase_token != "" && $request->device_id != "") {
                $firebaseToken = new FirebaseToken;
                $myToken = FirebaseToken::where("device_id", $request->device_id)->where('user_id', auth()->user()->id)->first();
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
        $validator = Validator::make($request->all(), [
            // NOT unique:saccos,name — the name may already exist as an unclaimed
            // directory entry that drivers were onboarded under. That case is a
            // CLAIM, not a collision; only an already-claimed name is rejected.
            'name' => 'required|string|max:120',
            'email' => 'required|email|unique:saccos,email|unique:users,email',
            'phone' => 'required|digits:10|unique:users,phone',
            'password' => 'required|string|min:8|confirmed',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $directory = app(SaccoDirectory::class);
        $claimable = $directory->unclaimedByName($request->name);

        if ($claimable === null && $directory->isNameTaken($request->name)) {
            return response()->json([
                'errors' => ['name' => ['This SACCO is already registered. Ask its admin to add you.']],
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
        $token = $user->createToken($user->firstname . '-AuthToken')->plainTextToken;

        return $this->respondWithToken($token, null);
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required_if:email,=,null',
            'email' => 'required_if:phone,=,null',
            'password' => 'required'
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }
        $credentials = request(['email', 'password']);
        $crew = null;
        $token = null;
        if ($request->has('phone')) {
            $crew = Crew::where('phone', $request->phone)->where('status', true)->first();
            if ($crew != null) {
                if (Hash::check($request->password, $crew->password)) {
                    Auth::loginUsingId($crew->user_id);
                } else {
                    $crew = null;
                }
            }
            $credentials = request(['phone', 'password']);
        }
        if ($crew == null) {
            if (!Auth::attempt($credentials )) {
                return response()->json(['error' => 'Invalid username/password'], 401);
            }
        }
        if (!Auth::check()) {
            return response()->json(['error' => 'Invalid username/password'], 401);
        }

        $user = Auth::user();
        $token = $user->createToken($user->firstname . '-AuthToken')->plainTextToken;
        //\Log::info('User Details:'.json_encode(auth('api')->user()));
        if ($request->firebase_token != "" && $request->device_id != "") {
            $firebaseToken = new FirebaseToken;
            $myToken = FirebaseToken::/*where("device_id", $request->device_id)->*/ where('user_id', auth()->user()->id)->first();
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
        $validator = Validator::make($request->all(), [
            'phone' => 'required|digits:10',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }
        $user = User::where('phone', $request->phone)->first();
        if($user == null){
            return response()->json(['error' => "Provided phone not found!"], 401);
        }
        $phone = "254" . intval($request->phone);
        $password = $this->generateRandomAlphabets(8);
        $message = "Hi " . $user->firstname . ". Your password has been successfully reset to " . $password . ". Login to your account and change the password";
        dispatch(new SendSMSJob($phone, $message));
        $user->password = app('hash')->make($password);

        if ($user->save()) {
            return response()->json(['success' => "New Password has been sent to " . $request->phone . ". Use it to login."]);
        } else {
            return response()->json(['error' => "We're having trouble reseting your password! Try again"], 401);
        }

    }

    /**
     * Get the authenticated User.
     *
     * @return \Illuminate\Http\JsonResponse
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
            'termini' => TerminusUser::with('terminus.place')->where('user_id', auth()->user()->id)->where('status', true)->get(),
            'sacco' => Sacco::where('id', auth()->user()->sacco_id)->first(),
            'crew' => $crew
        ]);
    }

    /**
     * Log the user out (Invalidate the token).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout()
    {
        //auth()->logout();
        FirebaseToken::where('user_id', auth()->user()->id)->delete();
        auth()->user()->tokens()->delete();
        return response()->json(['message' => 'Successfully logged out']);
    }

    /**
     * Refresh a token.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function refresh()
    {
        return $this->respondWithToken(auth()->refresh(), null);
    }

    /**
     * Get the token array structure.
     *
     * @param  string $token
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respondWithToken($token, $crew)
    {
        return response()->json([
            'user' => User::where('id', auth()->user()->id)->with(['gender', 'roles'])->first(),
            'crew' => $crew,
            'permissions' => auth()->user()->getAllPermissions()->pluck('name'),
            'vehicle_users' => VehicleUser::with('vehicle.seat', 'vehicle.sacco')->where('user_id', auth()->user()->id)
                ->where('status', true)->get(),
            'termini' => TerminusUser::with('terminus.place')->where('user_id', auth()->user()->id)->where('status', true)->get(),
            'sacco' => Sacco::where('id', auth()->user()->sacco_id)->first(),
            'access_token' => $token,
            'token_type' => 'bearer',
        ]);
    }
    function generateRandomAlphabets($length)
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
