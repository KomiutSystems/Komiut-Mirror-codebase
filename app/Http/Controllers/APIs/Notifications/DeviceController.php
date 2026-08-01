<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Notifications;

use App\Http\Controllers\Controller;
use App\Models\FirebaseToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * @group Notifications
 *
 * Push-device registration. The app registers its FCM token on sign-in and
 * removes it on sign-out; a user may have several devices.
 */
class DeviceController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Register this device for push
     *
     * Upsert by token: if the token already exists (e.g. a phone handed to
     * another account) it is re-assigned to the current user, matching the C#
     * device-handoff behaviour. Idempotent.
     *
     * @authenticated
     *
     * @bodyParam token string required The FCM registration token. Example: fabc123...
     * @bodyParam platform string The device platform. Example: ANDROID
     *
     * @response 200 {"success": true}
     */
    public function register(Request $request): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'token' => 'required|string',
            'platform' => 'nullable|string|max:20',
        ])->validate();

        FirebaseToken::updateOrCreate(
            ['firebase_token' => $data['token']],
            ['user_id' => $request->user()->id, 'platform' => $data['platform'] ?? null],
        );

        return response()->json(['success' => true]);
    }

    /**
     * Unregister this device
     *
     * Removes the token only if it belongs to the caller — a token you don't own
     * is silently a no-op success (don't reveal another account's device).
     *
     * @authenticated
     *
     * @response 200 {"success": true}
     */
    public function unregister(Request $request, string $token): JsonResponse
    {
        FirebaseToken::where('firebase_token', $token)
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['success' => true]);
    }
}
