<?php

namespace App\Http\Controllers\APIs;

use App\Http\Controllers\Controller;
use App\Models\Cash;
use App\Models\Gender;
use App\Models\Mpesa;
use App\Models\MpesaPaymentSetting;
use App\Models\Place;
use App\Models\Route;
use App\Models\Sacco;
use App\Models\Seat;
use App\Models\Seat;
use App\Models\Transaction;
use App\Models\Vehicle;
use Illuminate\Http\Request;

class IndexApiController extends Controller
{
    public function getGenders(Request $request){
        
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;

        $genders = Gender::where('name', 'LIKE', '%'.$request->search.'%')
        ->orderBy('name', 'ASC')->skip($offset)->take(20)->get();
        return response()->json(['genders'=>$genders]);
    }

    public function copyMpesaTransactions(){
        $transaction = Transaction::where('mpesa_id', '>', 0)->latest()->first();
        $mpesa_id = 0;
        if($transaction!=null){
            $mpesa_id = $transaction->mpesa_id;
        }
        $mpesas = Mpesa::where('id', '>', $mpesa_id)->skip(0)->take(1000)->get();
        foreach($mpesas as $mpesa){
            $transaction = new Transaction;
        }
    }

    public function copyCashTransactions(Request $request){
        $trans = Cash::latest()->first();
        $trans_id = 0;
        if($trans != null){
            $trans_id = $trans->trans_id;
        } 
        $url = /*urlencode (*/"https://komiut.co.ke/api/cashes/copy?trans_id=".$trans_id;//);
        $json = json_decode(file_get_contents($url), true);
        foreach($json["cashes"] as $cash){
            $from = $cash['selectedDepart'];
            $to = $cash['selectedDest'];
            $fromPlace = Place::where('name', $from)->first();
            if($fromPlace == null){
                $fromPlace = new Place;
                $fromPlace->name = $from;
                $fromPlace->save();
            }
            $toPlace = Place::where('name', $to)->first();
            if($toPlace == null){
                $toPlace = new Place;
                $toPlace->name = $from;
                $toPlace->save();
            }
            $route = Route::where('from_id', $fromPlace->id)->where('to_id', $toPlace->id)->first();
            if($route == null){
                $route = new Route;
                $route->from_id = $fromPlace->id;
                $route->to_id = $toPlace->id;
                $route->save();
            }
            $myCash = new Cash();
            $myCash->trans_id = $cash['cashId'];
            $myCash->route_id = $route->id;
            //$
            return $cash['id'];
        }
        return response()->json(['cashes'=>$json['cashes']]);
    }
    public function copySaccos(Request $request){
        $url = "https://komiut.co.ke/api/saccos/copy";
        $json = json_decode(file_get_contents($url), true);
        foreach($json["saccos"] as $sacco){
            $newSacco = Sacco::where('name', $sacco['name'])->first();
            if($newSacco == null){
                $newSacco = new Sacco;
            }
            $newSacco->name = $sacco['name'];
            $newSacco->slogan = $sacco['sacco_motto'];
            $newSacco->phone = $sacco['customer_care_no'];
            if($newSacco->save()){
                if($sacco['paybill'] != null &&  $sacco['consumer_secret'] != null && $sacco['consumer_key'] !=null && $sacco['passkey'] != null){
                    $mpesaPaymentSetting = MpesaPaymentSetting::where('sacco_id', $newSacco->id)->first();
                    if($mpesaPaymentSetting == null){
                        $mpesaPaymentSetting = new MpesaPaymentSetting;
                    }
                    $mpesaPaymentSetting->sacco_id = $newSacco->id;
                    $mpesaPaymentSetting->consumer_key = $sacco['consumer_key'];
                    $mpesaPaymentSetting->consumer_secret = $sacco['consumer_secret'];
                    $mpesaPaymentSetting->pass_key = $sacco['passkey'];
                    $mpesaPaymentSetting->business_short_code = $sacco['paybill'];
                    $mpesaPaymentSetting->payment_mode = "CustomerBuyGoodsOnline";
                    $mpesaPaymentSetting->save();
                }
            }
        }
        return response()->json(['success'=>'Saccos Imported successfully']);
    }
    public function copySeats(Request $request){
        $url = "https://komiut.co.ke/api/seats/copy";
        $json = json_decode(file_get_contents($url), true);
        foreach($json["seats"] as $seat){
            $seat = Seat::where('name', $seat['name'])->first();
            if($seat == null){
                $seat = new Seat;
            }
            $seat->name = $seat['name'];
            $seat->seats = $bus['plate'];
            $vehicle->fleet_no = $bus['fleet_no'];
            $vehicle->till_number = $bus['till'];
            $vehicle->merchant_short_code = $bus['merchant_short_code'];
            $vehicle->user_id = 1;

            if($newSacco->save()){
                if($sacco['paybill'] != null &&  $sacco['consumer_secret'] != null && $sacco['consumer_key'] !=null && $sacco['passkey'] != null){
                    $mpesaPaymentSetting = MpesaPaymentSetting::where('sacco_id', $newSacco->id)->first();
                    if($mpesaPaymentSetting == null){
                        $mpesaPaymentSetting = new MpesaPaymentSetting;
                    }
                    $mpesaPaymentSetting->sacco_id = $newSacco->id;
                    $mpesaPaymentSetting->consumer_key = $sacco['consumer_key'];
                    $mpesaPaymentSetting->consumer_secret = $sacco['consumer_secret'];
                    $mpesaPaymentSetting->pass_key = $sacco['passkey'];
                    $mpesaPaymentSetting->business_short_code = $sacco['paybill'];
                    $mpesaPaymentSetting->payment_mode = "CustomerBuyGoodsOnline";
                    $mpesaPaymentSetting->save();
                }
            }
        }
        return response()->json(['success'=>'Saccos Imported successfully']);
    }*/
    
    public function copyVehicles(Request $request){
        $url = "https://komiut.co.ke/api/vehicles/copy";
        $json = json_decode(file_get_contents($url), true);
        /*foreach($json["buses"] as $bus){
            $vehicle = Vehicle::where('plate', $bus['plate'])->first();
            if($vehicle == null){
                $vehicle = new Vehicle;
            }
            $sacco = Sacco::where('name', $bus['name'])->first();
            if($sacco != null){
                $vehicle->sacco_id = $sacco->id;
            }
            $vehicle->plate = $bus['plate'];
            $vehicle->fleet_no = $bus['fleet_no'];
            $vehicle->till_number = $bus['till'];
            $vehicle->merchant_short_code = $bus['merchant_short_code'];
            $vehicle->user_id = 1;

            if($newSacco->save()){
                if($sacco['paybill'] != null &&  $sacco['consumer_secret'] != null && $sacco['consumer_key'] !=null && $sacco['passkey'] != null){
                    $mpesaPaymentSetting = MpesaPaymentSetting::where('sacco_id', $newSacco->id)->first();
                    if($mpesaPaymentSetting == null){
                        $mpesaPaymentSetting = new MpesaPaymentSetting;
                    }
                    $mpesaPaymentSetting->sacco_id = $newSacco->id;
                    $mpesaPaymentSetting->consumer_key = $sacco['consumer_key'];
                    $mpesaPaymentSetting->consumer_secret = $sacco['consumer_secret'];
                    $mpesaPaymentSetting->pass_key = $sacco['passkey'];
                    $mpesaPaymentSetting->business_short_code = $sacco['paybill'];
                    $mpesaPaymentSetting->payment_mode = "CustomerBuyGoodsOnline";
                    $mpesaPaymentSetting->save();
                }
            }
        }
        return response()->json(['success'=>'Saccos Imported successfully']);*/
    }
}
