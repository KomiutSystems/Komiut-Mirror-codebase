<?php

namespace App\Console\Commands;

use App\Models\MpesaQrcodePayment;
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
        $url = "http://13.232.144.242/api/mpesa_qrcode_payments/copy/from";
        $mpesa_qrcode_payment =MpesaQrcodePayment::latest()->first();
        if($mpesa_qrcode_payment != null){
            $url = "http://13.232.144.242/api/mpesa_qrcode_payments/copy/from?created_at=".urlencode($mpesa_qrcode_payment->created_at);
        }
        //$created_at = Carbon::parse(urldecode(urlencode($qrcode_payment->created_at)));
        //return $created_at;
        $json = json_decode(file_get_contents($url), true);
        foreach ($json["mpesa_qrcode_payments"] as $payment) {
            if($payment['qrcode_payment']){
                $vehicle = Vehicle::where('plate', $payment['qrcode_payment']['vehicle']['plate'])->first();
                $seat_arrangement = null;
                if($payment['qrcode_payment']['seat_arrangement'] != null){
                    $seat_arrangement = SeatArrangement::where('name', $payment['qrcode_payment']['seat_arrangement']['name'])->first();
                };
                $user = null;
                if($payment['qrcode_payment']['user'] != null){
                    $user = User::where('email', $payment['qrcode_payment']['user']['email'])->first();
                }
                $created_at = Carbon::parse($payment['qrcode_payment']['created_at']);
                if($vehicle != null){
                    if(QrcodePayment::where('created_at', $created_at)->where('user_id', $user != null?$user->id:null)->where('vehicle_id', $vehicle->id)->count() == 0){
                        $id = DB::table('qrcode_payments')->insertGetId([
                            'vehicle_id'=>$vehicle->id,
                            'amount'=>$payment['qrcode_payment']['amount'],
                            'seat_arrangement_id'=>$seat_arrangement!=null?$seat_arrangement->id:null,
                            'user_id'=>$user != null?$user->id:null,
                            'status'=>$payment['qrcode_payment']['status'],
                            'created_at'=>Carbon::parse($payment['qrcode_payment']['created_at']),
                            'updated_at'=>Carbon::parse($payment['qrcode_payment']['updated_at']),
                        ]);

                        if($id > 0){
                            if(MpesaQrcodePayment::where('transid', $payment['transid'])->count()==0){
                            DB::table('mpesa_qrcode_payments')->insert([
                                ["transid"=>$payment['transid'],
                                "name"=>$payment['name'],
                                "amount"=>$payment['amount'],
                                "points"=>$payment['points'],
                                "phone"=>$payment["phone"],
                                "transdate"=>$payment['transdate'],
                                "qrcode_payment_id"=>$id,
                                "callback"=>$payment['callback'],
                                "redeemed"=>$payment['redeemed'],
                                "created_at"=>Carbon::parse($payment['created_at']),
                                'updated_at'=>Carbon::parse($payment['updated_at'])
                                ]
                            ]);
                        }
                        }
                    }
                }
            }
        }
    }
}
