<?php

namespace App\Http\Controllers\Services;

use App\Http\Controllers\Controller;
use App\Models\FirebaseToken;
use Illuminate\Http\Request;

class SendFCMMessageController extends Controller
{
    public function sendFCMNotification($token, $title, $message, $payload)
    {
        //$title = 'Queue Alert';
        //$message = 'Sample Message';

        $SERVER_API_KEY = '***REMOVED***';

        $data = [
            "registration_ids" => $token,
            //"to" => "$token",
            /*"notification" => [
                "title" => "",
                "body" => "",
                "sound" => "default",
                //"badge" => "1",
            ],*/
            "data" => [
                "title" => "$title",
                "body" => "$message",
                "payload" => $payload,
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
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $dataString);

        $response = curl_exec($ch);
        return $response;
        //dd($response);
    }

    public function sendTestNotification(){
        $tokens = FirebaseToken::pluck('firebase_token');
        return $this->sendFCMNotification($tokens, 'Test', 'Test 1', 'open_test');
    }
}
