<?php

namespace App\Console\Commands;

use App\Models\Summary;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Carbon\Carbon;

class GenerateVehicleSummaries extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:generate-vehicle-summaries';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $transactions = Transaction::where('summarized', false)->whereNotNull('vehicle_id')->take(500)->skip(0)->get();
        foreach ($transactions as $transaction) {
            $summary = Summary::where('vehicle_id', $transaction->vehicle_id)->where('trans_date', Carbon::parse($transaction->trans_date)
            ->format('Y-m-d'))->first();
            if($summary == null){
                $summary = new Summary;
                $summary->mpesa_amount = 0;
                $summary->cash_amount = 0;
                $summary->mpesa_txn = 0;
                $summary->cash_txn = 0;
            }
            $summary->vehicle_id = $transaction->vehicle_id;
            if($transaction->mpesa_id > 0){
                $summary->mpesa_amount = $summary->mpesa_amount + $transaction->amount;
                $summary->mpesa_txn = $summary->mpesa_txn+1;
            }else{
                $summary->cash_amount = $summary->cash_amount + $transaction->amount;
                $summary->cash_txn = $summary->cash_txn+1;
            }
            $summary->trans_date = Carbon::parse($transaction->trans_date)->format('Y-m-d');
            $summary->save();
            $transaction->summarized = true;
            $transaction->save();
        }
    }
}
