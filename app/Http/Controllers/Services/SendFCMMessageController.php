<?php

namespace App\Http\Controllers\Services;

use App\Http\Controllers\Controller;
use App\Models\FirebaseToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Google\Client as GoogleClient;
use Throwable;

class SendFCMMessageController extends Controller
{
    public function sendFCMNotification($token, $title, $message, $payload, $booking_id)
    {
        $projectId = config('services.fcm.default.project_id', 'komiut');

        // disk('local') is NOT cosmetic. Storage::path() resolves the DEFAULT
        // disk, and production runs FILESYSTEM_DISK=s3 while league/flysystem-aws-s3-v3
        // is not in composer.json — so the default disk cannot even be built and
        // this line threw on EVERY push before a single byte reached Google.
        // QUEUE_CONNECTION defaults to sync (config/queue.php:16), so that throw
        // landed inline inside the M-Pesa callback handler
        // (MpesaPaymentsController:519 and :572 dispatch this job): a push failure
        // could abort the request that records a real payment. The service-account
        // JSON lives on the app box under storage/app, which is exactly the
        // 'local' disk root — naming it pins the read to the filesystem the file
        // is actually on, whatever FILESYSTEM_DISK says.
        $credentialsFilePath = Storage::disk('local')->path(
            config('services.fcm.default.credentials', 'json/komiut-firebase-adminsdk-rq0kn-cce411b4e8.json')
        );

        // Best-effort from here on, mirroring App\Services\Notifications\FcmSender:
        // a missing credentials file or an unreachable Google must log and return,
        // never bubble. Only 6 device tokens across 4 users of 6,808 can even
        // receive a push — no push is ever worth failing the caller for.
        if (! is_file($credentialsFilePath)) {
            Log::warning('fcm(legacy): credentials file missing, skipping push', ['path' => $credentialsFilePath]);

            return null;
        }

        try {
            $client = new GoogleClient();

            $client->setAuthConfig($credentialsFilePath);
            $client->addScope('https://www.googleapis.com/auth/firebase.messaging');
            $client->refreshTokenWithAssertion();
            $mytoken = $client->getAccessToken();

            $access_token = $mytoken['access_token'] ?? null;
        } catch (Throwable $e) {
            Log::warning('fcm(legacy): could not obtain access token', ['error' => $e->getMessage()]);

            return null;
        }

        if ($access_token === null) {
            Log::warning('fcm(legacy): google returned no access token');

            return null;
        }

        $headers = [
            "Authorization: Bearer $access_token",
            'Content-Type: application/json'
        ];
        $data = [
            "message" => [
                "token" => $token, //use token for one
                /*"notification" => [
                    "title" => $title,
                    "body" => $message,
                ],*/
                "data" => [
                    "title" => $title,
                    "body" => $message,
                    "payload" => $payload,
                    "bookingid"=>"$booking_id",
                ]
            ]
        ];
        $mypayload = json_encode($data);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $mypayload);
        // No timeout meant "wait forever". On the sync queue connection this call
        // runs inside the M-Pesa callback request, so an unreachable
        // fcm.googleapis.com would hold a php-fpm worker open indefinitely — the
        // exact shape of the 2026-08-20 ingestion outage, where a wedged pool
        // stopped the fleet recording payments. 10s connect / 15s total matches
        // FcmSender's Http::timeout(15).
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        // Callers are queued jobs, not HTTP requests — a thrown/500 response here
        // reaches nobody. Log the failure and return so the next token still gets
        // its push instead of the whole loop dying on one bad send.
        if ($err) {
            Log::warning('fcm(legacy): curl error', ['error' => $err]);

            return null;
        }

        return json_decode($response, true);
/*
        \Log::info($booking_id);
        //$title = 'Queue Alert';
        //$message = 'Sample Message';

        $SERVER_API_KEY = 'AAAA72hVmKE:APA91bH7XEOwYftT006HjbaJFQB__VxB6Wc9funpAge8DRBxAbdSxta-ALRaup2_rXfkduwkGxO5VVnSa2h-zu86fh7R1PbT-NsbN3FoL2wAjE8W6TTiI6SYuQbk8zD1n55bN0tCKDPe';

        $data = [
            "registration_ids" => $token,
            //"to" => "$token",
            /*"notification" => [
                "title" => "",
                "body" => "",
                "sound" => "default",
                //"badge" => "1",
            ],*/
            /*"data" => [
                "title" => "$title",
                "body" => "$message",
                "payload" => $payload,
                "booking_id"=>$booking_id,
            ],
            "priority" => 10
        ];
        $dataString = json_encode($data);

        $headers = [
            'Authorization: key=' . $SERVER_API_KEY,
            'Content-Type: application/json',
        ];

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, 'https://fcm.googleapis.com/fcm/send');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $dataString);

        $response = curl_exec($ch);
        return $response;
        //dd($response);*/
    }

    public function sendTestNotification(){
        $tokens = FirebaseToken::where('user_id', 1)->pluck('firebase_token');
        foreach($tokens as $token){
            // sendFCMNotification now returns the decoded FCM body (or null when
            // the send was skipped/failed) instead of a JsonResponse, so that its
            // real callers — queued jobs — get a value rather than an HTTP object.
            // This debug route has to build its own response.
            $result = $this->sendFCMNotification($token, 'Test', 'Test 1', 'open_test', 12);

            return response()->json([
                'message' => $result === null ? 'Push was not sent — see logs.' : 'Notification has been sent',
                'response' => $result,
            ], $result === null ? 500 : 200);
        }

        return response()->json(['message' => 'No device tokens registered for user 1.'], 404);
    }
}
