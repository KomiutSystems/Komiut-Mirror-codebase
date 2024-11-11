<?php

namespace App\Console\Commands;

use App\Models\Cash;
use App\Models\Place;
use App\Models\Route;
use App\Models\Transaction;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CopyCash extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:copy-cash';

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
        $trans = Cash::orderBy('id', 'DESC')->first();
        $trans_id = 0;
        if ($trans != null) {
            $trans_id = $trans->trans_id;
        }
        $url = /*urlencode (*/"https://komiut.co.ke/api/cashes/copy?trans_id=" . urlencode($trans_id); //);
        $json = json_decode(file_get_contents($url), true);
        foreach ($json["cashes"] as $cash) {
            $from = $cash['selectedDepart'];
            $to = $cash['selectedDest'];
            $fromPlace = Place::where('name', $from)->first();
            if ($fromPlace == null) {
                $fromPlace = new Place;
                $fromPlace->name = $from;
                $fromPlace->status = 1;
                $fromPlace->save();
            }
            $toPlace = Place::where('name', $to)->first();
            if ($toPlace == null) {
                $toPlace = new Place;
                $toPlace->name = $from;
                $toPlace->status = 1;
                $toPlace->save();
            }
            $route = Route::where('from_id', $fromPlace->id)->where('to_id', $toPlace->id)->first();
            if ($route == null) {
                $route = new Route;
                $route->from_id = $fromPlace->id;
                $route->to_id = $toPlace->id;
                $route->status = 1;
                $route->save();
            }
            $myCash = Cash::where('trans_id', $cash['cashId'])->first();
            if ($myCash == null) {
                $myCash = new Cash();
            }
            $myCash->trans_id = $cash['cashId'];
            $myCash->route_id = $route->id;
            $myCash->from_id = $fromPlace->id;
            $myCash->to_id = $toPlace->id;
            $vehicle = Vehicle::where('plate', $cash['regno'])->first();
            if ($vehicle == null) {
                $vehicle = new Vehicle;
                $vehicle->plate = $cash['regno'];
                $vehicle->status = 1;
                $vehicle->user_id = 1;
                $vehicle->seat_id = 1;
                $vehicle->save();
            }
            $myCash->vehicle_id = $vehicle->id;
            $passname = explode(' ', $cash['passname']);
            $myCash->firstname = $passname[0];
            if (count($passname) > 1) {
                $myCash->lastname = $passname[1];
            }
            $myCash->phone = $cash['passphone'];
            $myCash->recieved_amount = $cash['amtGiven'];
            $myCash->fare_amount = $cash['amount'];
            $myCash->change_amount = $cash['stringChange'];
            $myCash->luggage_amount = $cash['luggage'];
            $myCash->total_amount = $cash['amount'] + $cash['luggage'];
            $myCash->trans_date = Carbon::parse($cash['transdate']);
            if ($myCash->save()) {
                $transaction = Transaction::where('cash_id', $myCash->id)->first();
                if ($transaction == null) {
                    $transaction = new Transaction();
                }
                $transaction->vehicle_id = $vehicle->id;
                $transaction->cash_id = $myCash->id;
                $transaction->amount = $myCash->total_amount;
                $transaction->trans_date = Carbon::parse($cash['transdate']);
                $transaction->save();
            }
        }
    }
}
