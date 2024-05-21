<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\MpesaBookingCallback;
use App\Models\MpesaQrcodePayment;
use App\Models\Point;
use App\Models\PointSetting;
use App\Models\PointTransaction;
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
        foreach ($pointSettings as $setting) {
            $mpesaBookingCallbacks = MpesaBookingCallback::with(['booking.queue.vehicle', 'booking.user'])->where('redeemed', false)
                ->where('created_at', '>=', $setting->start_date);
            if ($setting->sacco_id != null) {

                $mpesaBookingCallbacks = $mpesaBookingCallbacks->whereHas('booking.queue.vehicle', function ($query) use ($setting) {
                    $query->where('sacco_id', $setting->sacco_id);
                });
            }
            $mpesaBookingCallbacks = $mpesaBookingCallbacks->take(500)->get();
            foreach ($mpesaBookingCallbacks as $mpesaBookingCallback) {

                $point = Point::where('phone', $mpesaBookingCallback->booking->user->phone)->where('sacco_id', $setting->sacco_id)->first();
                if ($point == null) {
                    $point = new Point;
                    $point->start_date = $mpesaBookingCallback->created_at;
                    $point->points = $mpesaBookingCallback->amount / ($setting->points_type == "by items" ? $setting->items : $setting->amount);
                } else {
                    $point->points = $point->points + ($mpesaBookingCallback->amount / ($setting->points_type == "by items" ? $setting->items : $setting->amount));
                }
                $point->user_id = $mpesaBookingCallback->booking->user->id;
                $point->name = $mpesaBookingCallback->booking->user->firstname . ' ' . $mpesaBookingCallback->booking->user->lastname;
                $point->phone = $mpesaBookingCallback->booking->user->phone;
                $point->end_date = $mpesaBookingCallback->created_at;
                $point->sacco_id = $setting->sacco_id;
                if ($point->save()) {
                    $mpesaBookingCallback->redeemed = true;
                    $mpesaBookingCallback->points = ($mpesaBookingCallback->amount / ($setting->points_type == "by items" ? $setting->items : $setting->amount));
                    $mpesaBookingCallback->save();
                    $pointTransaction = PointTransaction::where('mpesa_booking_callback_id', $mpesaBookingCallback->id)->first();
                    if ($pointTransaction == null) {
                        $pointTransaction = new PointTransaction();
                    }
                    $pointTransaction->mpesa_booking_callback_id = $mpesaBookingCallback->id;
                    $pointTransaction->points = $mpesaBookingCallback->points;
                    $pointTransaction->trans_date = $mpesaBookingCallback->created_at;
                    $pointTransaction->save();
                }
            }

            $mpesaQrcodePayments = MpesaQrcodePayment::with(['qrcode_payment.user'])->where('redeemed', false)
                ->where('created_at', '>=', $setting->start_date)->whereHas('qrcode_payment.vehicle', function ($query) use ($setting) {
                    $query->where('sacco_id', $setting->sacco_id);
                })->take(500)->get();
            foreach ($mpesaQrcodePayments as $mpesaQrcodePayment) {
                $phone = '0' . substr($mpesaQrcodePayment->phone, 3);
                $point = Point::where('phone', $phone)->where('sacco_id', $setting->sacco_id)->first();
                if ($point == null) {
                    $point = new Point;
                    $point->start_date = $mpesaQrcodePayment->created_at;
                    $point->points = $mpesaQrcodePayment->amount / ($setting->points_type == "by items" ? $setting->items : $setting->amount);
                } else {
                    $point->points = $point->points + ($mpesaQrcodePayment->amount / ($setting->points_type == "by items" ? $setting->items : $setting->amount));
                }
                if ($mpesaQrcodePayment->user != null) {
                    $point->user_id = $mpesaQrcodePayment->qrcode_payment->user->id;
                    $point->name = $mpesaQrcodePayment->qrcode_payment->user->firstname . ' ' . $mpesaQrcodePayment->qrcode_payment->user->lastname;
                } else {
                    $point->name = '-';
                }
                $point->phone = $phone;
                $point->end_date = $mpesaQrcodePayment->created_at;
                $point->sacco_id = $setting->sacco_id;
                if ($point->save()) {
                    $mpesaQrcodePayment->redeemed = true;
                    $mpesaQrcodePayment->points = ($mpesaQrcodePayment->amount / ($setting->points_type == "by items" ? $setting->items : $setting->amount));
                    $mpesaQrcodePayment->save();
                    $pointTransaction = PointTransaction::where('mpesa_qrcode_payment_id', $mpesaQrcodePayment->id)->first();
                    if ($pointTransaction == null) {
                        $pointTransaction = new PointTransaction();
                    }
                    $pointTransaction->mpesa_qrcode_payment_id = $mpesaQrcodePayment->id;
                    $pointTransaction->points = $mpesaQrcodePayment->points;
                    $pointTransaction->trans_date = $mpesaQrcodePayment->created_at;
                    $pointTransaction->save();
                }
            }
        }
    }
}
