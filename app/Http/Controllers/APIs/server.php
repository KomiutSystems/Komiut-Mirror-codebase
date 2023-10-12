<?php

namespace App\Http\Controllers\APIs;
use App\Http\Controllers\Controller;
use App\Models\Mpesa;
use App\Models\Transaction;
use App\Models\Vehicle;
use Carbon\Carbon;

class server extends Controller
{
    public function CBAMpesaNotificationRequest($hashVal, $TransactionRequest){
        //\Log::info($TransactionRequest);
        $doc = new \DOMDocument();
        $doc->loadXML($TransactionRequest);

        $transtype= $doc->getElementsByTagName('TransType')->item(0)->nodeValue;
        $transid = $doc->getElementsByTagName('TransID')->item(0)->nodeValue;
        $transtime = Carbon::parse($doc->getElementsByTagName('TransTime')->item(0)->nodeValue);
        $transamount = $doc->getElementsByTagName('TransAmount')->item(0)->nodeValue;
        $business_short_code = $doc->getElementsByTagName('BusinessShortCode')->item(0)->nodeValue;
        //$billreferencenumber = $doc->getElementsByTagName('BillRefNumber')->item(0)->nodeValue;
        $orgaccountbalance = $doc->getElementsByTagName('OrgAccountBalance')->item(0)->nodeValue;
        $msisdn = $doc->getElementsByTagName('MSISDN')->item(0)->nodeValue;
        $firstname = "";;
        if($doc->getElementsByTagName('KYCValue')->length >= 1){
            $firstname = $doc->getElementsByTagName('KYCValue')->item(0)->nodeValue;
        }
        $middlename = "";//$doc->getElementsByTagName('KYCValue')->item(1)->nodeValue;
        if($doc->getElementsByTagName('KYCValue')->length >= 2){
            $middlename = $doc->getElementsByTagName('KYCValue')->item(1)->nodeValue;
        }
        $lastname = "";
        if($doc->getElementsByTagName('KYCValue')->length >= 3){
            $lastname = $doc->getElementsByTagName('KYCValue')->item(2)->nodeValue;
        }

        /*$data = 'NEOKENYAMPYA'.$transtype . $transid . 
            $transtime . $transamount . $business_short_code . $billreferencenumber . 
            $orgaccountbalance
            . $msisdn . $firstname . $middlename . $lastname;
    
        $hashVal1 = base64_encode(hash('sha256', $data));

        // if($hashVal != $hashVal1){
        //     $output = 'FAIL';//invalid hash
        // }else
        */
        if ($transamount > 0) {
            $mpesa = Mpesa::where('TransID', $transid)->first();
            if($mpesa == null){
                $mpesa = new Mpesa;
            }
            $mpesa->TransID = $transid;
            $mpesa->MSISDN = $msisdn;
            $mpesa->TransAmount = $transamount;
            $mpesa->TransTime = $transtime;
            $mpesa->FirstName = $firstname;
            $mpesa->LastName = $lastname;
            $mpesa->MiddleName = $middlename;
            $mpesa->BusinessShortCode = $business_short_code;
            $mpesa->TransactionType = $transtype;
            $mpesa->ThirdPartyTransID = "";
            $mpesa->InvoiceNumber  = ""; 
            $mpesa->BillRefNumber = "";
            if($mpesa->save()){
                $vehicle = Vehicle::where("merchant_short_code", $business_short_code)->first();
                
                $transaction = new Transaction;
                if($vehicle != null){
                    $transaction->vehicle_id = $vehicle->id;
                }
                $transaction->mpesa_id = $mpesa->id;
                $transaction->trans_date = $transtime;
                $transaction->amount = $transamount;
                $transaction->save();
                return "OK";
            }else{
                return "FAIL";
            }
        }else {
            return 'FAIL';//transaction failed
        }
        //do something here
        return 'FAIL';
    }
}
