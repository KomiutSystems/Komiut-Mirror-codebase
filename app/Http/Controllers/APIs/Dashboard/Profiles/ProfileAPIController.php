<?php

namespace App\Http\Controllers\APIs\Dashboard\Profiles;

use App\Http\Controllers\Controller;
use App\Models\Gender;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class ProfileAPIController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api');
    }

    public function editProfile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|integer|min:0',
            'firstname' => 'required|string',
            'lastname' => 'required|string',
            'dob' => 'required|date',
            'phone' => 'required|digits:10|unique:users,phone,' . $request->id,
            'gender' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }
        $gender = Gender::where('name', $request->gender)->first();
        $user = User::find($request->id);
        if ($user == null) {
            return response()->json(['error' => 'Invalid profile id provided!'], 401);
        }
        $user->firstname = $request->firstname;
        $user->lastname = $request->lastname;
        if ($gender != null) {
            $user->gender_id = $gender->id;
        }
        $user->dob = $request->dob;
        $user->phone = $request->phone;
        if ($user->save()) {
            return response()->json(['success' => 'Profile Update successfully!']);
        } else {
            return response()->json(['error' => 'Unable to update profile'], 401);
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
        $validator = Validator::make($request->all(), [
            'image' => 'required|image|mimes:jpeg,png,jpg|max:2048',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }
        $imageName = Auth::user()->id . '.' . $request->image->extension();
        $user = Auth::user();
        if ($user->image != null) {
            if (file_exists(public_path('/images/profiles/' . $user->image))) {
                unlink(public_path() . '/images/profiles/' . $user->image);
            }
        }
        if ($request->image->move(public_path('images/profiles'), $imageName)) {

            $user->image = $imageName;
            if ($user->save()) {
                return response()->json(['success' => 'Profile Picture updated successfully!', 'image' => $imageName]);
            } else {
                return response()->json(['error' => 'Unable to update profile picture'], 401);
            }
        } else {
            return response()->json(['error' => 'Unable to upload profile picture'], 401);
        }
    }
}