<?php

namespace App\Http\Controllers\APIs;

use App\Http\Controllers\Controller;
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
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api', ['except' => ['login', 'register']]);
    }
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'firstname' => 'required|string',
            'lastname' => 'required|string',
            'email' => 'required|email|unique:users',
            'phone' => 'required|digits:10|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'dob' => 'date|before:today',
            'gender' => 'required|exists:genders,name'
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }
        $gender = Gender::where('name', $request->gender)->first();

        $user = new User;
        $user->firstname = $request->firstname;
        $user->lastname = $request->lastname;
        $user->email = $request->email;
        $user->password = app('hash')->make($request->password);
        $user->phone = $request->phone;
        $user->dob = Carbon::parse($request->dob);
        $user->gender_id = $gender->id;
        if ($user->save()) {
            $role = Role::where('name', 'User')->first();
            if ($role == null) {
                $role = Role::create(['name' => 'User']);
            }
            $user->assignRole($role);
            $credentials = request(['email', 'password']);
            if (!$token = JWTAuth::attempt($credentials, ['exp' => Carbon::now()->addDays(1)->timestamp])) {
                return response()->json(['error' => 'Invalid username/password'], 401);
            }
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
                    $token = JWTAuth::fromUser(Auth::user());
                } else {
                    $crew = null;
                }
            }
            $credentials = request(['phone', 'password']);
        }
        if ($crew == null) {
            if (!$token = JWTAuth::attempt($credentials /*, ['exp' => Carbon::now()->addDays(1)->timestamp]*/)) {
                return response()->json(['error' => 'Invalid username/password'], 401);
            }
        }
        //\Log::info('User Details:'.json_encode(auth('api')->user()));
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
        return $this->respondWithToken($token, $crew);
    }

    /**
     * Get the authenticated User.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function user(Request $request)
    {
        $crew = null;
        if($request->crew_id > 0){
            $crew = Crew::find($request->crew_id);
        }
        return response()->json([
            'user' => User::with(['roles', 'gender', 'sacco'])->where('id', auth('api')->user()->id)->first(),
            'permissions' => auth()->user()->getAllPermissions()->pluck('name'),
            'vehicle_users' => VehicleUser::with('vehicle.seat', 'vehicle.sacco')->where('user_id', auth()->user()->id)
                ->where('status', true)->get(),
            'termini' => TerminusUser::with('terminus.place')->where('user_id', auth()->user()->id)->where('status', true)->get(),
            'sacco' => Sacco::where('id', auth()->user()->sacco_id)->where('status', true)->first(),
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
        auth()->logout();
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
            'sacco' => Sacco::where('id', auth()->user()->sacco_id)->where('status', true)->first(),
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
        ]);
    }
}
