<?php

namespace App\Console\Commands;

use App\Models\Point;
use App\Models\PointSetting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Console\Command;

class GenerateUserPoints extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:generate-user-points';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate user points in the background';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $pointSettings = PointSetting::where('completed', false)->get();
        foreach($pointSettings as $setting){
            $transactions = Transaction::with(['mpesa', 'cash', 'vehicle'])->where('redeemed', false)
            ->where('trans_date','>=', $setting->start_date)->whereHas('vehicle', function($query) use($setting){
                $query->where('sacco_id', $setting->sacco_id);
            })->take(1000)->get();
            foreach($transactions as $transaction){
                $phone = "";
                $name ="";
                if($transaction->mpesa_id > 0){
                    $phone = $transaction->mpesa->MSISDN;
                    $phone = '0'.substr($phone, 3);
                    $name = $transaction->mpesa->FirstName;
                    $name = trim($name." ".$transaction->mpesa->MiddleName);
                    $name = trim($name." ".$transaction->mpesa->LastName);
                }else{
                    $phone = $transaction->cash->phone;
                    if(strlen($phone)>10){
                        $phone = '0'.substr($phone, 3);
                    }
                    $name = trim($transaction->cash->firstname.' '.$transaction->cash->lastname);
                }
                $point = Point::where('phone', $phone)->where('sacco_id', $setting->sacco_id)->first();
                if($point == null){
                    $point = new Point;
                    $point->start_date = $transaction->trans_date;
                    $point->points = $transaction->amount/($setting->points_type=="by items"?$setting->items:$setting->amount);
                }else{
                    $point->points = $point->points+($transaction->amount/($setting->points_type=="by items"?$setting->items:$setting->amount));
                }
                $user = User::where('phone', '0'.$phone)->first();
                if($user != null){
                    $point->user_id = $user->id;
                }
                $point->name = $name;
                $point->phone = $phone;
                $point->end_date = $transaction->trans_date;
                $point->sacco_id = $setting->sacco_id;
                if($point->save()){
                    $transaction->redeemed = true;
                    $transaction->save();
                }
            }
        }
    }
}
