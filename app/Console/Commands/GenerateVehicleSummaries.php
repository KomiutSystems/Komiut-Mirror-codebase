<?php

namespace App\Console\Commands;

use App\Models\Summary;
use App\Models\SummarySync;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Carbon\Carbon;
use DB;

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
        $summary_sync = SummarySync::latest()->first();
        $transDate = Carbon::today()->toDateString();
        if($summary_sync == null){
            $transaction = Transaction::orderBy('trans_date', 'DESC')->first();
            if($transaction != null){
                $transDate = Carbon::parse($transaction->trans_date)->toDateString();
            }
            $summary_sync = new SummarySync();
            $summary_sync->sync_date = $transDate;
            $summary_sync->save();
        }else{
            $transDate = Carbon::parse($summary_sync->sync_date)->toDateString();
            if($summary_sync->status){
                $transDate = Carbon::parse($summary_sync->sync_date)->addDay()->toDateString();
            }
        }
        $summaries = Summary::where('trans_date', $transDate)->get();
        foreach($summaries as $summary){
            $transactions = Transaction::select('vehicle_id', DB::raw('SUM(amount) as totals, SUM(CASE WHEN mpesa_id>0 THEN amount ELSE 0 END) as mpesa_totals,
            SUM(CASE WHEN cash_id>0 THEN amount ELSE 0 END) as cash_totals, SUM(CASE WHEN cash_id>0 THEN 1 ELSE 0 END) as cash_count,  SUM(CASE WHEN mpesa_id>0 THEN 1 ELSE 0 END) as mpesa_count,'))
            ->whereBetween('trans_date', [$transDate, Carbon::parse($transDate)->addDay()->toDateString()])
            ->groupBy('vehicle_id')->get();
            foreach($transactions as $transaction){
                $summary->vehicle_id = $transaction->vehicle_id;
                $summary->mpesa_amount = $transaction->mpesa_totals;
                $summary->cash_amount = $transaction->cash_totals;
                $summary->mpesa_txn = $transaction->mpesa_count;
                $summary->cash_txn = $transaction->mpesa_count;
                $summary->save();
            }
        }
        /*

        $transactions = Transaction::where('summarized', false)->whereNotNull('vehicle_id')->take(500)->skip(0)->get();
        foreach ($transactions as $transaction) {
            DB::transaction(function () use ($transaction) {
                $transDate = Carbon::parse($transaction->trans_date)->format('Y-m-d');
                //lock the row summary
                $summary = Summary::where('vehicle_id', $transaction->vehicle_id)
                    ->where('trans_date', $transDate)
                    ->lockForUpdate()->first();
                if ($summary == null) {
                    $summary = new Summary;
                    $summary->mpesa_amount = 0;
                    $summary->cash_amount = 0;
                    $summary->mpesa_txn = 0;
                    $summary->cash_txn = 0;
                }
                $summary->vehicle_id = $transaction->vehicle_id;
                if ($transaction->mpesa_id > 0) {
                    $summary->mpesa_amount = $summary->mpesa_amount + $transaction->amount;
                    $summary->mpesa_txn = $summary->mpesa_txn + 1;
                } else {
                    $summary->cash_amount = $summary->cash_amount + $transaction->amount;
                    $summary->cash_txn = $summary->cash_txn + 1;
                }
                $summary->trans_date = Carbon::parse($transaction->trans_date)->format('Y-m-d');
                $summary->save();
                $transaction->summarized = true;
                $transaction->save();
            });
        }*/
    }
}
