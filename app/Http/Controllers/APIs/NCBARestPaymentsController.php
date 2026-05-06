<?php

namespace App\Http\Controllers\APIs;

use App\Http\Controllers\Controller;
use App\Models\Mpesa;
use App\Models\MpesaLog;
use App\Models\Summary;
use App\Models\Transaction;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Http\Request;

class NCBARestPaymentsController extends Controller
{
    public function restMpesaPayments(Request $request)
    {
        //$res = json_decode($request->getContent());

        $mpesaLog = new MpesaLog;
        $mpesaLog->log = json_encode($request->all());
        $mpesaLog->ip_address = $request->getClientIp();
        $mpesaLog->save();

        $res = $request;
        $transid = $res->TransID;

        $mpesaLog->trans_id = $transid;
        $mpesaLog->save();

        $msisdn = $res->MSISDN;
        $transamount = $res->TransAmount;
        $transtime = Carbon::parse($res->TransTime);
        $firstname = isset($res->FirstName) ? $res->FirstName : "";
        $middlename = isset($res->MiddleName) ? $res->MiddleName : "";
        $lastname = isset($res->LastName) ? $res->LastName : "";
        $thirdpartytransid = isset($res->ThirdPartyTransID) ? $res->ThirdPartyTransID : "";
        $orgaccountbalance = isset($res->OrgAccountBalance) ? $res->OrgAccountBalance : "";
        $invoicenumber = isset($res->InvoiceNumber) ? $res->InvoiceNumber : "";
        $billreference = isset($res->BillRefNumber) ? $res->BillRefNumber : "";
        $business_short_code = $res->BusinessShortCode;
        $transtype = isset($res->TransactionType) ? $res->TransactionType : "";

        if ($transamount > 0) {
            $mpesa = Mpesa::where('TransID', $transid)->first();
            if ($mpesa == null) {
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
            $mpesa->ThirdPartyTransID = $thirdpartytransid;
            $mpesa->InvoiceNumber = $invoicenumber;
            $mpesa->BillRefNumber = $billreference;
            if ($mpesa->save()) {
                //$vehicle = Vehicle::where("merchant_short_code", $business_short_code)->first();
                if ($business_short_code == "880100") {
                    $vehicle = Vehicle::where("till_number", $billreference)->first();
                } else {
                    $vehicle = Vehicle::where("merchant_short_code", $business_short_code)->first();
                }
                $transaction = Transaction::where('mpesa_id', $mpesa->id)->first();
                if ($transaction == null) {
                    $transaction = new Transaction;
                    if ($vehicle != null) {
                        $transaction->vehicle_id = $vehicle->id;
                        $summary = Summary::where('vehicle_id', $transaction->vehicle_id)->where('trans_date',
                            Carbon::parse($mpesa->TransTime)
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
                return '{"ResultCode":0, "ResultDesc":"sucessful validation", "ThirdPartyTransID": 0}';
            } else {
                return '{"ResultCode":1, "ResultDesc":"Failed Transaction", "ThirdPartyTransID": 1}';
            }
        } else {
            return '{"ResultCode":1, "ResultDesc":"Invalid amount", "ThirdPartyTransID": 1}'; //transaction failed
        }

    }
    public function mpesaNewPayments(Request $request)
    {
        $soapResponse = $request->getContent();
        $xmlObject = simplexml_load_string($soapResponse);
        $jsonString = json_encode($xmlObject);
        $jsonObject = json_decode($jsonString);

        $response = json_decode($this->savePayments($jsonObject));
        return $response->ResultCode == 0 ? "OK" : "FAIL";
    }

    public function restMpesaNewPayments(Request $request)
    {
        return $this->savePayments($request);
    }
    public function savePayments($request)
    {

        $mpesaLog = new MpesaLog;
        $mpesaLog->log = json_encode($request);
        $mpesaLog->save();
        //\Log::info($request->all());
        if (!isset($request->Username) || !isset($request->Password)) {
            return '{"ResultCode":1, "ResultDesc":"Username/Password Required"}'; //transaction failed
        }

        if ($request->Username == "komiut" && $request->Password == "komiut@#234user!!") {
            $res = $request;
            $transid = $res->TransID;

            $mpesaLog->trans_id = $transid;
            $mpesaLog->save();

            $msisdn = $res->Mobile;
            $transamount = $res->TransAmount;
            $transtime = Carbon::parse($res->TransTime);
            $name = explode(" ", isset($res->Name) ? $res->Name : (isset($res->name) ? $res->name : ""));
            $firstname = $name[0];
            $middlename = "";
            $lastname = "";
            if (count($name) > 1) {
                $middlename = $name[1];
            }
            if (count($name) > 2) {
                $lastname = $name[2];
            }
            //$middlename = isset($res->MiddleName)?$res->MiddleName:"";
            //$lastname = isset($res->LastName)?$res->LastName:"";

            $thirdpartytransid = isset($res->ThirdPartyTransID) ? $res->ThirdPartyTransID : "";
            $orgaccountbalance = isset($res->OrgAccountBalance) ? $res->OrgAccountBalance : "";
            $invoicenumber = isset($res->InvoiceNumber) ? $res->InvoiceNumber : "";
            $billreference = isset($res->BillRefNumber) ? $res->BillRefNumber : "";
            $business_short_code = $res->BusinessShortCode;
            $transtype = isset($res->TransactionType) ? $res->TransactionType : "";

            if ($transamount > 0) {

                $mpesa = Mpesa::where('TransID', $transid)->first();
                if ($mpesa == null) {
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
                $mpesa->ThirdPartyTransID = $thirdpartytransid;
                $mpesa->InvoiceNumber = $invoicenumber;
                $mpesa->BillRefNumber = $billreference;
                if ($mpesa->save()) {
                    $vehicle = Vehicle::where("merchant_short_code", $business_short_code)->first();
                    $transaction = Transaction::where('mpesa_id', $mpesa->id)->first();
                    if ($transaction == null) {
                        $transaction = new Transaction;
                        if ($vehicle != null) {
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
                    return '{"ResultCode":0, "ResultDesc":"sucessful validation", "ThirdPartyTransID": 0}';
                } else {
                    return '{"ResultCode":1, "ResultDesc":"Failed Transaction"}';
                }
            } else {
                return '{"ResultCode":1, "ResultDesc":"Invalid amount"}'; //transaction failed
            }
        } else {
            return '{"ResultCode":1, "ResultDesc":"Wrong Username/Password"}';
        }
    }

}
