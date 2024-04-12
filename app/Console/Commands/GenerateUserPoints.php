<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Point;
use App\Models\PointSetting;
use App\Models\QrcodePayment;
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
        $pointSettings = PointSetting::where('status', true)->get();
        foreach($pointSettings as $setting){
            $bookings = Booking::with(['user'])->where('redeemed', false)->where("paid", true)
            ->where('created_at','>=', $setting->start_date)->whereHas('queue.vehicle', function($query) use($setting){
                $query->where('sacco_id', $setting->sacco_id);
            })->take(500)->get();
            foreach($bookings as $booking){

                $point = Point::where('phone', $booking->user->phone)->where('sacco_id', $setting->sacco_id)->first();
                if($point == null){
                    $point = new Point;
                    $point->start_date = $booking->created_at;
                    $point->points = $booking->amount/($setting->points_type=="by items"?$setting->items:$setting->amount);
                }else{
                    $point->points = $point->points+($booking->amount/($setting->points_type=="by items"?$setting->items:$setting->amount));
                }
                $point->user_id = $booking->user->id;
                $point->name = $booking->user->firstname.' '.$booking->user->lastname;
                $point->phone = $booking->user->phone;
                $point->end_date = $booking->created_at;
                $point->sacco_id = $setting->sacco_id;
                if($point->save()){
                    $booking->redeemed = true;
                    $booking->save();
                }
            }

            $qrcodePayments = QrcodePayment::with(['user', 'mpesa_qrcode_payment'])->where('redeemed', false)->where("status", true)
            ->where('created_at','>=', $setting->start_date)->whereHas('vehicle', function($query) use($setting){
                $query->where('sacco_id', $setting->sacco_id);
            })->take(500)->get();
            foreach($qrcodePayments as $qrcodePayment){
                $phone ='0'.substr($qrcodePayment->mpesa_qrcode_payment->phone, 3);
                $point = Point::where('phone', $phone)->where('sacco_id', $setting->sacco_id)->first();
                if($point == null){
                    $point = new Point;
                    $point->start_date = $qrcodePayment->created_at;
                    $point->points = $qrcodePayment->amount/($setting->points_type=="by items"?$setting->items:$setting->amount);
                }else{
                    $point->points = $point->points+($qrcodePayment->amount/($setting->points_type=="by items"?$setting->items:$setting->amount));
                }
                if($qrcodePayment->user != null){
                $point->user_id = $qrcodePayment->user->id;
                $point->name = $qrcodePayment->user->firstname.' '.$qrcodePayment->user->lastname;
                }else{
                    $point->name = '-';
                }
                $point->phone = $phone;
                $point->end_date = $qrcodePayment->created_at;
                $point->sacco_id = $setting->sacco_id;
                if($point->save()){
                    $qrcodePayment->redeemed = true;
                    $qrcodePayment->save();
                }
            }
        }
    }
}
