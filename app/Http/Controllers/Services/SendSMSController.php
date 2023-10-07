<?php

namespace App\Http\Controllers\Services;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SendSMSController extends Controller
{
    
    public function sendSMS($phone, $message)
    {
        $curl = curl_init();

        curl_setopt_array(
            $curl,
            array(
                CURLOPT_URL => 'https://sms.tenasms.com/api/services/sendsms',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_POSTFIELDS => 'apikey=35cef1afb8e2cba10d4b981e6d673ee0&partnerID=4052&message=' . urlencode($message) . '&shortcode=KOMIUT&mobile=' . $phone,
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/x-www-form-urlencoded',
                    'Cookie: PHPSESSID=tsjel5rclvs3vlt2co1cjnq4ua'
                ),
            )
        );

        $curl_response = curl_exec($curl);
        curl_close($curl);
        $response = json_decode($curl_response, true);
        return $response;
    }
}
