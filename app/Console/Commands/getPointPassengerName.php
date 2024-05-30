<?php

namespace App\Console\Commands;

use App\Models\Mpesa;
use App\Models\MpesaBookingCallback;
use App\Models\MpesaQrcodePayment;
use App\Models\Point;
use Illuminate\Console\Command;

class getPointPassengerName extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:get-point-passenger-name';

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
        $points = Point::where('name', '-')->skip(0)->take(500)->get();
        foreach($points as $point){
            $phone = '254'.intval($point->phone);
            $payment = MpesaBookingCallback::where('phone', $phone)->first();
            if($payment == null){
                $payment = MpesaQrcodePayment::where('phone', $phone)->first();
            }
            \Log::info($payment);
            /*$name = '*';
            if($payment != null){
                $mpesa = Mpesa::where('TransID', $payment->trans_id)->first();
                if($mpesa != null){
                    $name = $mpesa->FirstName.' '.$mpesa->MiddleName.' '.$mpesa->LastName;
                }
            }
            MpesaBookingCallback::where('phone', $phone)->where('name', null)->update(['name'=>$name]);
            MpesaQrcodePayment::where('phone', $phone)->where('name', null)->update(['name'=>$name]);
            $point->name = $name;
            $point->save();*/
        }
    }
}
