<?php

namespace App\Http\Controllers\Services;

use App\Http\Controllers\Controller;
use App\Models\FirebaseToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Google\Client as GoogleClient;

class SendFCMMessageController extends Controller
{
    public function sendFCMNotification($token, $title, $message, $payload, $booking_id)
    {
        $projectId = "komiut";

        $credentialsFilePath = Storage::path('json/komiut-firebase-adminsdk-rq0kn-cce411b4e8.json');
        $client = new GoogleClient();

        $client->setAuthConfig($credentialsFilePath);
        $client->addScope('https://www.googleapis.com/auth/firebase.messaging');
        $client->refreshTokenWithAssertion();
        $mytoken = $client->getAccessToken();

        $access_token = $mytoken['access_token'];
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
        curl_setopt($ch, CURLOPT_VERBOSE, true); // Enable verbose output for debugging
        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return response()->json([
                'message' => 'Curl Error: ' . $err
            ], 500);
        } else {
            return response()->json([
                'message' => 'Notification has been sent',
                'response' => json_decode($response, true)
            ]);
        }
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
            return $this->sendFCMNotification($token, 'Test', 'Test 1', 'open_test', 12);
        }
    }
}
