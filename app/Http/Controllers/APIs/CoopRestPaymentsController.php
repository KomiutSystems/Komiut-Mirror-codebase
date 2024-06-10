<?php

namespace App\Http\Controllers\APIs;

use App\Http\Controllers\Controller;
use App\Models\CoopMpesaStkCallback;
use App\Models\Mpesa;
use App\Models\MpesaLog;
use App\Models\Summary;
use App\Models\Transaction;
use App\Models\Vehicle;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CoopRestPaymentsController extends Controller
{
    public function coopMpesaPayments(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'Amount' => 'required|numeric|min:1',
            'TransactionDate' => 'required|string',
            'Narration' => 'required|string',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->messages()], 400);
        }
        $mpesaLog = new MpesaLog;
        $mpesaLog->log = json_encode($request->all());
        $mpesaLog->save();

        $amount = $request->Amount;
        $narration = explode("~", $request->Narration);
        $transDate = Carbon::parse(str_replace('+', ' ', $request->TransactionDate));
        $transId = $narration[0];
        $businessShortCode = $narration[1];
        $phone = $narration[2];
        $name = explode(" ", $narration[3]); //was 3
        $firstname = $name[0];
        $middlename = "";
        $lastname = "";
        try {
            $transDate = Carbon::parse($narration[4]);
        } catch (Exception $e) {
            $transDate = Carbon::now('Africa/Nairobi');
        }
        if (count($name) > 1) {
            $middlename = $name[1];
        }
        if (count($name) > 2) {
            $lastname = $name[2];
        }
        $mpesaLog->trans_id = $transId;
        $mpesaLog->save();

        $mpesa = Mpesa::where('TransID', $transId)->first();
        if ($mpesa == null) {
            $mpesa = new Mpesa;
        }
        $mpesa->TransID = $transId;
        $mpesa->MSISDN = $phone;
        $mpesa->TransAmount = $amount;
        $mpesa->FirstName = $firstname;
        $mpesa->MiddleName = $middlename;
        $mpesa->LastName = $lastname;
        $mpesa->TransTime = $transDate;
        $mpesa->BusinessShortCode = $businessShortCode;
        $mpesa->ThirdPartyTransID = "";
        $mpesa->InvoiceNumber = "";
        $mpesa->BillRefNumber = "";
        $mpesa->TransactionType = "";
        if ($mpesa->save()) {
            $vehicle = Vehicle::where('merchant_short_code', $businessShortCode)->first();
            $transaction = Transaction::where('mpesa_id', $mpesa->id)->first();
            if ($transaction == null) {
                $transaction = new Transaction;

                $transaction->amount = $amount;
                if ($vehicle != null) {
                    $transaction->vehicle_id = $vehicle->id;
                    $summary = Summary::where('vehicle_id', $transaction->vehicle_id)
                        ->where('trans_date', Carbon::parse($mpesa->TransTime)
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
                $transaction->trans_date = $transDate;
                $transaction->save();
            }
        }
        return response()->json(["MessageCode" => "200", "Message" => "Successfully received data"]);
    }

    public function coopMpesaStkCallback(Request $request){
        $content = json_decode($request->getContent());
        $coopMpesaCallback = new CoopMpesaStkCallback();
        $coopMpesaCallback->callback = json_encode($content);
        if($coopMpesaCallback->save()){
            return response()->json(['success'=>'Success']);
        }else{
            return response()->json(['error'=>'Unable to save response!'], 400);
        }
    }

    public function coopMpesaStk(Request $request){
        //to be implemented
    }
}
