<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use DB;
use App\Models\PointSetting;
use App\Models\Sacco;
use Carbon\Carbon;
use Spatie\Permission\Models\Role;

class CopyPointSettings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:copy-point-settings';

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
        $url = "http://13.232.144.242/api/point_settings/copy/from";
        $point = PointSetting::latest()->first();
        if ($point != null) {
            $url = "http://13.232.144.242/api/point_settings/copy/from?created_at=" . urlencode($point->created_at);
        }
        //$created_at = Carbon::parse(urldecode(urlencode($qrcode_payment->created_at)));
        //return $created_at;
        $json = json_decode(file_get_contents($url), true);
        foreach ($json["point_settings"] as $point_setting) {
            $sacco = null;
            if ($point_setting['sacco'] != null) {
                $sacco = Sacco::where('name', $point_setting['sacco']['name'])->first();
            }
            $role = null;
            if ($point_setting['role'] != null) {
                $role = Role::where('name', $point_setting['role']['name'])->first();
            }
            if (
                PointSetting::where('amount', $point_setting['amount'])
                    ->where('amount', $point_setting['amount'])->
                    where('items', $point_setting['items'])->
                    where('points_on', $point_setting['points_on'])->
                    where('points_type', $point_setting['points_type'])->
                    where('role_id', $role != null ? $role->id : null)->
                    where('sacco_id', $sacco != null ? $sacco->id : null)->
                    where('start_date', Carbon::parse($point_setting["start_date"]))
                    ->count() == 0
            ) {
                DB::table('point_settings')->insert([
                    "amount" => $point_setting['amount'],
                    "items" => $point_setting['items'],
                    "points_on" => $point_setting['points_on'],
                    "points_type" => $point_setting['points_type'],
                    "role_id" => $role != null ? $role->id : null,
                    "sacco_id" => $sacco != null ? $sacco->id : null,
                    "start_date" => Carbon::parse($point_setting['start_date']),
                    "completed" => $point_setting['completed'],
                    "status" => $point_setting['status'],
                    'created_at' => Carbon::parse($point_setting['created_at']),
                    'updated_at' => Carbon::parse($point_setting['updated_at']),
                ]);
            }
        }
    }
}
