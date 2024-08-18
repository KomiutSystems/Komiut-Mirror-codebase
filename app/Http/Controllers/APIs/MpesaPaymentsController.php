<?php

namespace App\Http\Controllers\APIs;

use App\Http\Controllers\Controller;
use App\Jobs\SendFCMJob;
use App\Models\Booking;
use App\Models\MpesaBookingCallback;
use App\Models\MpesaQrcodePayment;
use App\Models\MpesaStkCallback;
use App\Models\QrcodePayment;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class MpesaPaymentsController extends Controller
{
    protected $BusinessShortCode;
    protected $passkey;
    protected $consumer_key;
    protected $consumer_secret;
    protected $till;
    protected $paymentMode;
    protected $url = "";

    public function lipaNaMpesaPassword()
    {
        $lipa_time = Carbon::rawParse('now')->format('YmdHms');
        $timestamp = $lipa_time;
        $lipa_na_mpesa_password = base64_encode(intval($this->BusinessShortCode) . $this->passkey . $timestamp);
        return $lipa_na_mpesa_password;
    }
    public function customerMpesaSTKPush(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "phone" => "string|min:9|max:12|required",
            "amount" => "integer|required",
            "booking_id" => "required|min:1|integer",
        ]);
        $phone = $request->phone;
        if (strlen($request->phone) <= 10) {
            $phone = "254" . intval($request->phone);
        }
        if ($validator->fails()) {
            return response()->json(["errors" => $validator->messages()], 400);
        }
        $booking = Booking::with('queue.vehicle.sacco.mpesa_payment', 'queue.vehicle.mpesa_payment_setting')->where('id', $request->booking_id)->first();
        if ($booking != null) {
            if ($booking->queue->vehicle->mpesa_payment_setting != null) {
                $this->BusinessShortCode = $booking->queue->vehicle->mpesa_payment_setting->business_short_code;
                $this->passkey = $booking->queue->vehicle->mpesa_payment_setting->pass_key;
                $this->consumer_key = $booking->queue->vehicle->mpesa_payment_setting->consumer_key;
                $this->consumer_secret = $booking->queue->vehicle->mpesa_payment_setting->consumer_secret;
                $this->till = $booking->queue->vehicle->till_number;
                $this->paymentMode = $booking->queue->vehicle->mpesa_payment_setting->payment_mode;
                $this->url = $booking->queue->vehicle->mpesa_payment_setting->is_live ? 'https://api' : 'https://sandbox';
            } else if ($booking->queue->vehicle->sacco != null) {
                if ($booking->queue->vehicle->sacco->mpesa_payment != null) {
                    $this->BusinessShortCode = $booking->queue->vehicle->sacco->mpesa_payment->business_short_code;
                    $this->passkey = $booking->queue->vehicle->sacco->mpesa_payment->pass_key;
                    $this->consumer_key = $booking->queue->vehicle->sacco->mpesa_payment->consumer_key;
                    $this->consumer_secret = $booking->queue->vehicle->sacco->mpesa_payment->consumer_secret;
                    $this->till = $booking->queue->vehicle->till_number;
                    $this->paymentMode = $booking->queue->vehicle->sacco->mpesa_payment->payment_mode;
                    $this->url = $booking->queue->vehicle->sacco->mpesa_payment ? 'https://api' : 'https://sandbox';
                } else {
                    return response()->json(['error' => 'No payments found for this sacco'], 401);
                }
            } else {
                return response()->json(['error' => 'Vehicle Sacco Not found!'], 401);
            }
        } else {
            return response()->json(['error' => 'Invalid booking id'], 401);
        }

        if (
            $this->BusinessShortCode == null || $this->passkey == null ||
            $this->consumer_key == null || $this->consumer_secret == null
        ) {
            return response()->json(['error' => 'Invalid keys provided'], 401);
        }
        /*
        $bus = \DB::table('buses')->where('plate', $request->plate)->first();
        if($bus == null){
            return response()->json(["error"=>"Vehicle not found!"], 401);
        }*/
        $token = $this->generateAccessToken();
        if ($token == "") {
            return response()->json(["error" => "Returned empty access token!"], 401);
        }
        $url = $this->url . '.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type:application/json', 'Authorization:Bearer ' . $token));
        $curl_post_data = [
            'BusinessShortCode' => intval($this->BusinessShortCode),
            'Password' => $this->lipaNaMpesaPassword(),
            'Timestamp' => Carbon::rawParse('now')->format('YmdHms'),
            'TransactionType' => $this->paymentMode,//'CustomerPayBillOnline' : 'CustomerBuyGoodsOnline',
            'Amount' => intval($request->amount),
            'PartyA' => intval($phone),
            'PartyB' => intval($this->paymentMode == "CustomerPayBillOnline" ? $this->BusinessShortCode : $this->till),
            'PhoneNumber' => intval($phone),
            'CallBackURL' => url('/') . '/api/stk/push/response?booking_id=' . $request->booking_id,
            //'CallBackURL' => 'https://5f72-154-159-237-0.ngrok-free.app/api/stk/push/response?booking_id='.$request->booking_id,
            'AccountReference' => "" . $request->booking_id,
            'TransactionDesc' => "Online Booking"
        ];
        //return $curl_post_data;
        $data_string = json_encode($curl_post_data);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data_string);
        $curl_response = curl_exec($curl);

        $response = json_decode($curl_response, true);

        $mpesaStkCallback = new MpesaStkCallback;
        $mpesaStkCallback->booking_id = $request->booking_id;
        $mpesaStkCallback->callback = json_encode($response);
        $mpesaStkCallback->save();
        return $response;
    }

    public function customerQRCodeSTKPush(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "vehicle_id" => "required|integer|exists:vehicles,id",
            "amount" => "integer|required|min:20",
            "phone" => "required|digits:10",
            "seat_id" => "nullable|integer|exists:seat_arrangements,id",
            "user_id" => "nullable|integer|exists:users,id",
        ]);
        if ($validator->fails()) {
            return response()->json(["errors" => $validator->messages()], 400);
        }
        $phone = $request->phone;
        if (strlen($request->phone) <= 10) {
            $phone = "254" . intval($request->phone);
        }
        if ($validator->fails()) {
            return response()->json(["errors" => $validator->messages()], 400);
        }
        $vehicle = Vehicle::with('sacco.mpesa_payment', 'mpesa_payment_setting')->find($request->vehicle_id);
        if ($vehicle != null) {
            if ($vehicle->sacco != null) {
                if ($vehicle->mpesa_payment_setting != null) {
                    $this->BusinessShortCode = $vehicle->mpesa_payment_setting->business_short_code;
                    $this->passkey = $vehicle->mpesa_payment_setting->pass_key;
                    $this->consumer_key = $vehicle->mpesa_payment_setting->consumer_key;
                    $this->consumer_secret = $vehicle->mpesa_payment_setting->consumer_secret;
                    $this->till = $vehicle->till_number;
                    $this->paymentMode = $vehicle->mpesa_payment_setting->payment_mode;
                    $this->url = $vehicle->mpesa_payment_setting->is_live ? 'https://api' : 'https://sandbox';
                } else if ($vehicle->sacco->mpesa_payment != null) {
                    $this->BusinessShortCode = $vehicle->sacco->mpesa_payment->business_short_code;
                    $this->passkey = $vehicle->sacco->mpesa_payment->pass_key;
                    $this->consumer_key = $vehicle->sacco->mpesa_payment->consumer_key;
                    $this->consumer_secret = $vehicle->sacco->mpesa_payment->consumer_secret;
                    $this->till = $vehicle->till_number;
                    $this->paymentMode = $vehicle->sacco->mpesa_payment->payment_mode;
                    $this->url = $vehicle->sacco->mpesa_payment->is_live ? 'https://api' : 'https://sandbox';
                } else {
                    return response()->json(['error' => 'No payments found for this sacco'], 401);
                }
            } else {
                return response()->json(['error' => 'Vehicle Sacco Not found!'], 401);
            }
        } else {
            return response()->json(['error' => 'Invalid Vehicle'], 401);
        }

        if (
            $this->BusinessShortCode == null || $this->passkey == null ||
            $this->consumer_key == null || $this->consumer_secret == null
        ) {
            return response()->json(['error' => 'Invalid keys provided'], 401);
        }
        /*
        $bus = \DB::table('buses')->where('plate', $request->plate)->first();
        if($bus == null){
            return response()->json(["error"=>"Vehicle not found!"], 401);
        }*/
        $token = $this->generateAccessToken();
        if ($token == "") {
            return response()->json(["error" => "Returned empty access token!"], 401);
        }
        $qrcodePayment = new QrcodePayment;
        $qrcodePayment->vehicle_id = $vehicle->id;
        $qrcodePayment->user_id = $request->user_id;
        $qrcodePayment->seat_arrangement_id = $request->seat_id;
        $qrcodePayment->amount = $request->amount;
        if ($qrcodePayment->save()) {
            $url = $this->url . '.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type:application/json', 'Authorization:Bearer ' . $token));
            $curl_post_data = [
                'BusinessShortCode' => intval($this->BusinessShortCode),
                'Password' => $this->lipaNaMpesaPassword(),
                'Timestamp' => Carbon::rawParse('now')->format('YmdHms'),
                'TransactionType' => $this->paymentMode,//'CustomerPayBillOnline' : 'CustomerBuyGoodsOnline',
                'Amount' => intval($request->amount),
                'PartyA' => intval($phone),
                'PartyB' => intval($this->paymentMode == "CustomerPayBillOnline" ? $this->BusinessShortCode : $this->till),
                'PhoneNumber' => intval($phone),
                'CallBackURL' => url('/') . '/api/stk/push/response?qrcode_payment_id=' . $qrcodePayment->id,
                //'CallBackURL' => 'https://02a4-154-159-237-183.ngrok-free.app/api/stk/push/response?qrcode_payment_id=' . $qrcodePayment->id,
                'AccountReference' => "" . $qrcodePayment->id,
                'TransactionDesc' => "Online Booking"
            ];
            //return $curl_post_data;
            $data_string = json_encode($curl_post_data);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data_string);
            $curl_response = curl_exec($curl);

            $response = json_decode($curl_response, true);

            $mpesaStkCallback = new MpesaStkCallback;
            $mpesaStkCallback->qrcode_payment_id = $qrcodePayment->id;
            $mpesaStkCallback->callback = json_encode($response);
            $mpesaStkCallback->save();
            return $response;
        } else {
            return response()->json(['error' => 'Unable to proceed with payments'], 401);
        }
    }
    public function generateAccessToken()
    {
        $consumer_key = $this->consumer_key;
        $consumer_secret = $this->consumer_secret;
        $credentials = base64_encode($consumer_key . ":" . $consumer_secret);

        $ch = curl_init($this->url . '.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Basic ' . $credentials]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $response = curl_exec($ch);
        curl_close($ch);
        $myResponse = json_decode($response);
        //echo $response;
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode == 200) {
            Log::info('ERROR: ' . $response);
            return $myResponse->access_token;
        } else {
            Log::info('ERROR: ' . $response);
            return "";
        }
    }

    /**
     * J-son Response to M-pesa API feedback - Success or Failure
     */
    public function createValidationResponse($result_code, $result_description)
    {
        $result = json_encode(["ResultCode" => $result_code, "ResultDesc" => $result_description]);
        $response = new Response();
        $response->headers->set("Content-Type", "application/json; charset=utf-8");
        $response->setContent($result);
        return $response;
    }
    /**
     *  M-pesa Validation Method
     * Safaricom will only call your validation if you have requested by writing an official letter to them
     */
    public function mpesaValidation(Request $request)
    {
        $result_code = "0";
        $result_description = "Accepted validation request.";
        return $this->createValidationResponse($result_code, $result_description);
    }

    public function stkResponse(Request $request)
    {
        //\Log::info("booking_id:".$request->booking_id);
        //\Log::info($request->getContent());
        $content = json_decode($request->getContent());
        if ($content->Body->stkCallback->ResultCode == 0) {
            //\Log::info(json_encode($content->Body->stkCallback->ResultDesc));
            //\Log::info(json_encode($content->Body->stkCallback->CallbackMetadata);
            $items = $content->Body->stkCallback->CallbackMetadata->Item;
            $amount = 0.0;
            $transid = "";
            $transdate = "";
            $phone = "";
            foreach ($items as $item) {
                $name = (string) $item->Name;
                if ($name != "Balance") {
                    $amount = $name == "Amount" ? (double) $item->Value : $amount;
                    $transid = $name == "MpesaReceiptNumber" ? (string) $item->Value : $transid;
                    $transdate = $name == "TransactionDate" ? "" . substr((string) $item->Value, 0, 4) . "-" . substr((string) $item->Value, 4, 2) . "-" . substr((string) $item->Value, 6, 2) . " " . substr((string) $item->Value, 8, 2) . ":" . substr((string) $item->Value, 10, 2) . ":" . substr((string) $item->Value, 12, 2) : $transdate;
                    $phone = $name == "PhoneNumber" ? (string) $item->Value : $phone;
                }
            }
            if ($request->booking_id > 0) {
                $bookings = Booking::find($request->booking_id);
                $bookings->paid = true;
                $bookings->save();

                $mpesaBookingCallback = new MpesaBookingCallback;
                $mpesaBookingCallback->transid = $transid;
                $mpesaBookingCallback->phone = $phone;
                $mpesaBookingCallback->transdate = Carbon::parse($transdate);
                $mpesaBookingCallback->booking_id = $request->booking_id;
                $mpesaBookingCallback->amount = $amount;
                $mpesaBookingCallback->callback = json_encode($content);
                $mpesaBookingCallback->save();
                $this->paymentsNotification($request->booking_id);
            } else {
                $qrcodePayment = QrcodePayment::find($request->qrcode_payment_id);
                $qrcodePayment->status = true;
                $qrcodePayment->save();

                $mpesaQrcodePayment = new MpesaQrcodePayment;
                $mpesaQrcodePayment->transid = $transid;
                $mpesaQrcodePayment->phone = $phone;
                $mpesaQrcodePayment->transdate = Carbon::parse($transdate);
                $mpesaQrcodePayment->qrcode_payment_id = $request->qrcode_payment_id;
                $mpesaQrcodePayment->amount = $amount;
                $mpesaQrcodePayment->callback = json_encode($content);
                $mpesaQrcodePayment->save();
                //$this->paymentsNotification($request->qrcode_payment_id);
            }
        }
    }

    /**
     * M-pesa Transaction confirmation method, we save the transaction in our databases
     */
    public function mpesaConfirmation(Request $request)
    {
        Log::info($request->getContent());
        $content = json_decode($request->getContent());

        $transid = $content->TransID;
        $msisdn = $content->MSISDN;
        $transamount = $content->TransAmount;
        $transtime = Carbon::parse($content->TransTime);
        $firstname = isset($content->FirstName) ? $content->FirstName : "";
        $middlename = isset($content->MiddleName) ? $content->MiddleName : "";
        $lastname = isset($content->LastName) ? $content->LastName : "";
        $thirdpartytransid = isset($content->ThirdPartyTransID) ? $content->ThirdPartyTransID : "";
        $orgaccountbalance = isset($content->OrgAccountBalance) ? $content->OrgAccountBalance : "";
        $invoicenumber = isset($content->InvoiceNumber) ? $content->InvoiceNumber : "";
        //should come with the id of the queue
        $billreference = isset($content->BillRefNumber) ? $content->BillRefNumber : "";
        $business_short_code = isset($content->BusinessShortCode) ? $content->BusinessShortCode : "";
        $transtype = isset($content->TransactionType) ? $content->TransactionType : "";

        //save to db
        if (\DB::table("mpesas")->where("TransID", $transid)->count() == 0) {
            $id = \DB::table("mpesas")->insertGetId([
                "user_id" => 0,
                "TransID" => $transid,
                "MSISDN" => $msisdn,
                "TransAmount" => $transamount,
                "TransTime" => $transtime,
                "FirstName" => $firstname,
                "LastName" => $lastname,
                "MiddleName" => $middlename,
                "BusinessShortCode" => $business_short_code,
                "isUsed" => 0,
                "TransactionType" => $transtype,
                "ThirdPartyTransID" => $thirdpartytransid,
                "InvoiceNumber" => $invoicenumber,
                "BillRefNumber" => $billreference,
                "created_at" => \Carbon\Carbon::now()
            ]);
            if ($id > 0) {
                $car = \DB::table("buses")->where("merchant_short_code", $billreference)->first();
                $car_id = $car != null ? $car->id : 0;
                \DB::table("transactions")->insert(["status" => 1, "amount" => $transamount, "mpesa_id" => $id, "cash_id" => 0, "bank_id" => 0, "approve_by" => 0, "car_id" => $car_id, "created_at" => $transtime]);
                \DB::table("passengers")->where("id", $billreference)->update(["status" => 1]);
            }
        }

        $response = new Response();
        $response->headers->set("Content-Type", "text/xml; charset=utf-8");
        $response->setContent(json_encode(["C2BPaymentConfirmationResult" => "Success"]));
        return $response;
    }

    public function mpesaRegisterUrls()
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $this->url . '.safaricom.co.ke/mpesa/c2b/v1/registerurl');
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type:application/json', 'Authorization: Bearer ' . $this->generateAccessToken()));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt(
            $curl,
            CURLOPT_POSTFIELDS,
            json_encode(
                array(
                    'ShortCode' => "174379",
                    'ResponseType' => 'Completed',
                    'ConfirmationURL' => "https://komiut.co.ke/api/transaction/confirmation",
                    'ValidationURL' => "https://komiut.co.ke/api/validation"
                )
            )
        );
        $curl_response = curl_exec($curl);
        echo $curl_response;
    }
    public function paymentsNotification($booking_id)
    {
        $title = 'Payments Received';
        //$message = '';
        $user = Booking::with('to', 'from', 'queue.route.from', 'queue.route.to', 'user.firebase_tokens')->where('id', $booking_id)->first();
        if ($user->user->firebase_tokens->count() > 0) {
            $from = $user->queue->route->from->name;
            $to = $user->queue->route->to->name;
            if ($user->from != null) {
                $from = $user->from->name;
            }
            if ($user->to != null) {
                $to = $user->to->name;
            }

            $message = "KSH " . number_format($user->amount, 2) . " received for " . $from . " to " . $to;
            $tokens = $user->user->firebase_tokens->pluck('firebase_token');
            //$SERVER_API_KEY = 'AAAA72hVmKE:APA91bH7XEOwYftT006HjbaJFQB__VxB6Wc9funpAge8DRBxAbdSxta-ALRaup2_rXfkduwkGxO5VVnSa2h-zu86fh7R1PbT-NsbN3FoL2wAjE8W6TTiI6SYuQbk8zD1n55bN0tCKDPe';
            dispatch(new SendFCMJob($tokens, $title, $message, 'payments', $booking_id));
            /*$data = [
                "registration_ids" => $tokens,
                //"to" => "$token",
                "notification" => [
                    "title" => "$title",
                    "body" => "$message",
                    "sound" => "default",
                    //"badge" => "1",
                ],
                "data" => [
                    "booking_id" => $booking_id,
                    "queue_type" => $user->tripId == 0 ? 0 : 1
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
            //dd($response);*/
        } else {
            return "User not found";
        }
    }


    public function qrcodePaymentsNotification($qrcode_payment_id)
    {
        $title = 'Payments Received';
        //$message = '';
        $user = QrcodePayment::with('vehicle.sacco', 'user.firebase_tokens')->where('id', $qrcode_payment_id)->first();
        if ($user->user != null) {
            if ($user->user->firebase_tokens->count() > 0) {

                $message = "KSH " . number_format($user->amount, 2) . " received for as payments for " . $user->vehicle->plate;
                $tokens = $user->user->firebase_tokens->pluck('firebase_token');
                dispatch(new SendFCMJob($tokens, $title, $message, 'qrcode_payments', $qrcode_payment_id));

            }
        }
    }
}
