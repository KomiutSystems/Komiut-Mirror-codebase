<?php

namespace App\Http\Controllers\Dashboard\Profile;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;

class ProfileController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }
    public function index()
    {
        $user = User::with(['roles', 'gender'])->findOrFail(\Auth::user()->id);
        return view('dashboard.profile.profile', ['user' => $user]);
    }

    public function editProfile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|integer|min:1',
            'firstname' => 'required|string',
            'lastname' => 'required|string',
            'dob' => 'required|date|before:today',
            'gender' => 'required|min:1',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        // Self-service profile edit: always the authenticated user, never a
        // client-supplied id (which let anyone edit any user's profile).
        $user = $request->user();
        $user->firstname = $request->firstname;
        $user->dob = $request->dob;
        $user->lastname = $request->lastname;
        $user->gender_id = $request->gender;
        if ($user->save()) {
            return response()->json(['success' => 'User updated successfully!']);
        } else {
            return response()->json(['error' => 'Unable to update user'], 401);
        }
    }
    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password' => 'required|min:8|string|same:confirm_password',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }
        if (Hash::check($request->current_password, auth()->user()->password)) {
            //save
            $user = User::find(Auth::user()->id);
            $user->password = Hash::make($request->new_password);
            if ($user->save()) {
                return response()->json(['success' => 'Password update successfully!']);
            } else {
                return response()->json(['error' => 'Unable to update password!'], 401);
            }
        } else {
            return response()->json(['error' => 'Current Password is incorrect!'], 401);
        }
    }
    public function uploadProfilePicture(Request $request)
    {
        $folderPath = public_path('images/profiles/');
        $user = User::findOrFail(Auth::user()->id);
        if ($user->image != null) {
            if (file_exists(public_path('/images/profiles/' . $user->image))) {
                unlink(public_path() . '/images/profiles/' . $user->image);
            }
        }
        $data = $request->image;
        list($type, $data) = explode(';', $data);
        list(, $data) = explode(',', $data);

        $data = base64_decode($data);
        $image_name = Auth::user()->id . '.png';
        $path = $folderPath . $image_name;

        file_put_contents($path, $data);
        $user->image = $image_name;
        $user->save();

        return response()->json(['success' => 'Image Uploaded Successfully']);
    }
}