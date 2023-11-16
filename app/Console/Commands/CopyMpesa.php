<?php

namespace App\Console\Commands;

use App\Models\Mpesa;
use App\Models\Transaction;
use App\Models\Vehicle;
use App\Models\Summary;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CopyMpesa extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'copy:mpesa';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Copy mpesa transactions from previous main server before disabling it';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $mpesa = Mpesa::orderBy('id', 'desc')->first();
        $mpesa_id = 0;
        if ($mpesa != null) {
            $mpesa_id = $mpesa->TransID;
        }
        $url = /*urlencode (*/"https://komiut.co.ke/api/mpesas/copy?trans_id=" . urlencode($mpesa_id); //);
        $json = json_decode(file_get_contents($url), true);
        foreach ($json["mpesas"] as $mpesa) {

            $myMpesa = Mpesa::where('TransID', $mpesa['TransID'])->first();
            if ($myMpesa == null) {
                $myMpesa = new Mpesa();
            }
            $myMpesa->TransID = $mpesa['TransID'];
            $myMpesa->MSISDN = $mpesa['MSISDN'];
            $myMpesa->TransAmount = $mpesa['TransAmount'];
            $myMpesa->TransTime = $mpesa['TransTime'];
            $myMpesa->FirstName = $mpesa['FirstName'];
            $myMpesa->LastName = $mpesa['LastName'];
            $myMpesa->MiddleName = $mpesa['MiddleName'];
            $myMpesa->ThirdPartyTransID = $mpesa['ThirdPartyTransID'];
            $myMpesa->InvoiceNumber = $mpesa['InvoiceNumber'];
            $myMpesa->BillRefNumber = $mpesa['BillRefNumber'];
            $myMpesa->BusinessShortCode = $mpesa['BusinessShortCode'];
            $myMpesa->TransactionType = $mpesa['TransactionType'];
            if ($myMpesa->save()) {
                $transaction = Transaction::where('mpesa_id', $myMpesa->id)->first();
                if ($transaction == null) {
                    $transaction = new Transaction();
                }
                $vehicle = Vehicle::where('merchant_short_code', $myMpesa->BusinessShortCode)->first();
                if ($vehicle != null) {
                    $transaction->vehicle_id = $vehicle->id;
                    $summary = Summary::where('vehicle_id', $transaction->vehicle_id)->where('trans_date', Carbon::parse($myMpesa->TransTime)
                        ->format('Y-m-d'))->first();
                    if ($summary == null) {
                        $summary = new Summary;
                        $summary->mpesa_amount = 0;
                        $summary->cash_amount = 0;
                        $summary->mpesa_txn = 0;
                        $summary->cash_txn = 0;
                    }
                    $summary->vehicle_id = $vehicle->id;
                    $summary->mpesa_amount = $summary->mpesa_amount + $myMpesa->TransAmount;
                    $summary->mpesa_txn = $summary->mpesa_txn + 1;

                    $summary->trans_date = Carbon::parse($myMpesa->TransTime)->format('Y-m-d');
                    $summary->save();
                }
                $transaction->mpesa_id = $myMpesa->id;
                $transaction->amount = $myMpesa->TransAmount;
                $transaction->trans_date = Carbon::parse($myMpesa->TransTime);
                $transaction->save();
            }
        }
    }
}
