<?php

namespace App\Console\Commands;

use App\Models\Point;
use App\Models\Sacco;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use DB;

class CopyPoints extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:copy-points';

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
        $url = "http://13.232.144.242/api/points/copy/from";
        $point = Point::latest()->first();
        if ($point != null) {
            $url = "http://13.232.144.242/api/points/copy/from?created_at=" . urlencode($point->created_at);
        }
        //$created_at = Carbon::parse(urldecode(urlencode($qrcode_payment->created_at)));
        //return $created_at;
        $json = json_decode(file_get_contents($url), true);
        foreach ($json["points"] as $point) {
            $user = null;
            if ($point['user'] != null) {
                $user = User::where('email', $point['user']['email'])->first();
            }
            $sacco = null;
            if ($point['sacco'] != null) {
                $sacco = Sacco::where('name', $point['sacco']['name'])->first();
            }
            if (Point::where('phone', $point['phone'])->count() == 0) {
                DB::table('points')->insert([
                    "user_id" => $user != null ? $user->id : null,
                    "name" => $point["name"],
                    "phone" => $point['phone'],
                    'start_date' => Carbon::parse($point['start_date']),
                    'end_date' => Carbon::parse($point['end_date']),
                    'points' => $point['points'],
                    'redeemed' => $point['redeemed'],
                    "sacco_id" => $sacco != null ? $sacco->id : null,
                    'status' => $point['status'],
                    'created_at' => Carbon::parse($point['created_at']),
                    'updated_at' => Carbon::parse($point['updated_at']),
                ]);
            } else {
                DB::table('points')->where('phone', $point['phone'])->update([
                    "user_id" => $user != null ? $user->id : null,
                    "name" => $point["name"],
                    "phone" => $point['phone'],
                    'start_date' => Carbon::parse($point['start_date']),
                    'end_date' => Carbon::parse($point['end_date']),
                    'points' => $point['points'],
                    'redeemed' => $point['redeemed'],
                    "sacco_id" => $sacco != null ? $sacco->id : null,
                    'status' => $point['status'],
                    'created_at' => Carbon::parse($point['created_at']),
                    'updated_at' => Carbon::parse($point['updated_at']),
                ]);
            }
        }
    }
}
