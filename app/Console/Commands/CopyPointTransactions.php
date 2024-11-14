<?php

namespace App\Console\Commands;

use App\Models\MpesaBookingCallback;
use App\Models\MpesaQrcodePayment;
use App\Models\PointTransaction;
use Carbon\Carbon;
use Illuminate\Console\Command;
use DB;

class CopyPointTransactions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:copy-point-transactions';

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
        $url = "http://13.232.144.242/api/point_transactions/copy/from";
        $point = PointTransaction::latest()->first();
        if ($point != null) {
            $url = "http://13.232.144.242/api/point_transactions/copy/from?created_at=" . urlencode($point->created_at);
        }
        //$created_at = Carbon::parse(urldecode(urlencode($qrcode_payment->created_at)));
        //return $created_at;
        $json = json_decode(file_get_contents($url), true);
        foreach ($json["point_transactions"] as $point_transaction) {
            //"mpesa_booking_callback_id", "mpesa_qrcode_payment_id", "points", "trans_date"
            $mpesa_booking_callback = null;
            if ($point_transaction['mpesa_booking_callback'] != null) {
                $mpesa_booking_callback = MpesaBookingCallback::where('transid', $point_transaction['mpesa_booking_callback']['transid'])->first();
            }
            $mpesa_qrcode_payment = null;
            if ($point_transaction['mpesa_qrcode_payment'] != null) {
                $mpesa_qrcode_payment = MpesaQrcodePayment::where('transid', $point_transaction['mpesa_qrcode_payment']['transid'])->first();
            }
            if (
                PointTransaction::where('mpesa_booking_callback_id', $mpesa_booking_callback != null ? $mpesa_booking_callback->id : null)
                    ->where('mpesa_qrcode_payment_id', $mpesa_qrcode_payment != null ? $mpesa_qrcode_payment->id : null)->

                    count() == 0
            ) {
                DB::table('point_transactions')->insert([
                    "mpesa_booking_callback_id" => $mpesa_booking_callback != null ? $mpesa_booking_callback->id : null,
                    "mpesa_qrcode_payment_id" => $mpesa_qrcode_payment != null ? $mpesa_qrcode_payment->id : null,
                    "points" => $point_transaction['points'],
                    "trans_date" => Carbon::parse($point_transaction['trans_date']),
                    'created_at' => Carbon::parse($point_transaction['created_at']),
                    'updated_at' => Carbon::parse($point_transaction['updated_at']),
                ]);
            }
        }
        return response()->json(['success' => "Point Transactions imported successfully"]);
    }
}
