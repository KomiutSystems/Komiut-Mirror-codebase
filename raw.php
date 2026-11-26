<?php
require 'vendor/autoload.php'; $app = require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Schema;
$ids = ['UKQ4340ZXN','UKQ434178L'];
$m = DB::table('mpesas')->whereIn('TransID',$ids)->get();
foreach ($m as $r) { $a=(array)$r; unset($a['MSISDN'],$a['FirstName'],$a['MiddleName'],$a['LastName']); echo json_encode($a), "\n"; }
echo "transactions cols: ", implode(',', Schema::getColumnListing('transactions')), "\n";
