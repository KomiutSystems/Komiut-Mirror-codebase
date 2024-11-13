<?php

namespace App\Console\Commands;

use App\Models\QrcodePayment;
use App\Models\SeatArrangement;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Console\Command;
use DB;

class CopyQrcodePayments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:copy-qrcode-payments';

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
        $url = "http://13.232.144.242/api/qrcode_payments/copy/from";
        $qrcode_payment =QrcodePayment::latest()->first();
        if($qrcode_payment != null){
            $url = "http://13.232.144.242/api/qrcode_payments/copy/from?created_at=".urlencode($qrcode_payment->created_at);
        }
        //$created_at = Carbon::parse(urldecode(urlencode($qrcode_payment->created_at)));
        //return $created_at;
        $json = json_decode(file_get_contents($url), true);
        foreach ($json["qrcode_payments"] as $payment) {
            $vehicle = Vehicle::where('plate', $payment['vehicle']['plate'])->first();
            $seat_arrangement = null;
            if($payment['seat_arrangement'] != null){
                $seat_arrangement = SeatArrangement::where('name', $payment['seat_arrangement']['name'])->first();
            };
            $user = null;
            if($payment['user'] != null){
                $user = User::where('email', $payment['user']['email'])->first();
            }
            $created_at = Carbon::parse($payment['created_at']);
            if($user != null && $vehicle != null){
                if(QrcodePayment::where('created_at', $created_at)->where('user_id', $user->id)->where('vehicle_id', $vehicle->id)->count() == 0){
                    DB::table('qrcode_payments')->insert([
                        'vehicle_id'=>$vehicle->id,
                        'amount'=>$payment['amount'],
                        'seat_arrangement_id'=>$seat_arrangement!=null?$seat_arrangement->id:null,
                        'user_id'=>$user != null?$user->id:null,
                        'status'=>$payment['status'],
                        'created_at'=>Carbon::parse($payment['created_at']),
                        'updated_at'=>Carbon::parse($payment['updated_at']),
                    ]);
                }
            }
        }
    }
}
