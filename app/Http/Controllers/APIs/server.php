<?php

namespace App\Http\Controllers\APIs;
use App\Http\Controllers\Controller;
use App\Models\Mpesa;
use App\Models\MpesaLog;
use App\Models\Summary;
use App\Models\Transaction;
use App\Models\Vehicle;
use Carbon\Carbon;

class server extends Controller
{
    public function CBAMpesaNotificationRequest($hashVal, $TransactionRequest){
        $mpesaLog = new MpesaLog;
        $mpesaLog->log = $TransactionRequest;
        $mpesaLog->save();

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

        if ($transamount > 0) {
            $mpesa = Mpesa::where('TransID', $transid)->first();
            if($mpesa == null){
                $mpesa = new Mpesa;
            }
            $mpesa->TransID = $transid;

            $mpesaLog->trans_id = $transid;
            $mpesaLog->save();

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
                $transaction = Transaction::where('mpesa_id', $mpesa->id)->first();
                if($transaction == null){
                    $transaction = new Transaction;

                    if($vehicle != null){
                        $transaction->vehicle_id = $vehicle->id;
                        $summary = Summary::where('vehicle_id', $transaction->vehicle_id)->where('trans_date', Carbon::parse($mpesa->TransTime)
                            ->format('Y-m-d'))->first();
                        if ($summary == null) {
                            $summary = new Summary;
                            $summary->mpesa_amount = 0;
                            $summary->cash_amount = 0;
                            $summary->mpesa_txn = 0;
                            $summary->cash_txn = 0;
                        }
                        $summary->vehicle_id = $vehicle->id;
                        $summary->mpesa_amount = $summary->mpesa_amount + $mpesa->TransAmount;
                        $summary->mpesa_txn = $summary->mpesa_txn + 1;

                        $summary->trans_date = Carbon::parse($mpesa->TransTime)->format('Y-m-d');
                        $summary->save();
                        $transaction->summarized = true;
                    }
                    $transaction->mpesa_id = $mpesa->id;
                    $transaction->trans_date = $transtime;
                    $transaction->amount = $transamount;
                    $transaction->save();
                }
                return "OK";
            }else{
                return "FAIL";
            }
        }else {
            return 'FAIL';//transaction failed
        }
        //do something here
        //return 'FAIL';
    }
}
