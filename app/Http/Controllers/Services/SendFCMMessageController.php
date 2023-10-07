<?php

namespace App\Http\Controllers\Services;

use App\Http\Controllers\Controller;
use App\Models\FirebaseToken;
use Illuminate\Http\Request;

class SendFCMMessageController extends Controller
{
    public function sendFCMNotification($token, $title, $message)
    {
        //$title = 'Queue Alert';
        //$message = 'Sample Message';

        $SERVER_API_KEY = 'AAAA72hVmKE:APA91bH7XEOwYftT006HjbaJFQB__VxB6Wc9funpAge8DRBxAbdSxta-ALRaup2_rXfkduwkGxO5VVnSa2h-zu86fh7R1PbT-NsbN3FoL2wAjE8W6TTiI6SYuQbk8zD1n55bN0tCKDPe';

        $data = [
            "registration_ids" => $token,
            //"to" => "$token",
            "notification" => [
                "title" => "$title",
                "body" => "$message",
                "sound" => "default",
                //"badge" => "1",
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
        return $this->sendFCMNotification($tokens, 'Test', 'Test 1');
    }
}
