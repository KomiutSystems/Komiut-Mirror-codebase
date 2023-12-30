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
use App\Models\SaccoUser;
use App\Models\SaccoVehicle;
use App\Models\Seat;
use App\Models\SeatArrangement;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

class IndexApiController extends Controller
{
    public function getGenders(Request $request)
    {

        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;

        $genders = Gender::where('name', 'LIKE', '%' . $request->search . '%')
            ->orderBy('name', 'ASC')->skip($offset)->take(20)->get();
        return response()->json(['genders' => $genders]);
    }

    public function copyMpesaTransactions()
    {
        $mpesa = Mpesa::orderBy('id', 'desc')->first();
        $mpesa_id = 0;
        if ($mpesa != null) {
            $mpesa_id = $mpesa->TransID;
        }
        $url = /*urlencode (*/"https://komiut.co.ke/api/mpesas/copy?trans_id=" . urlencode($mpesa_id); //);
        $json = json_decode(file_get_contents($url), true);
        foreach ($json["mpesas"] as $mpesa) {

            $myMpesa = Mpesa::where('TransID', $mpesa['TransID'])->first();
            if ($myMpesa == null) {
                $myMpesa = new Mpesa();
            }
            $myMpesa->TransID = $mpesa['TransID'];
            $myMpesa->MSISDN = $mpesa['MSISDN'];
            $myMpesa->TransAmount = $mpesa['TransAmount'];
            $myMpesa->TransTime = $mpesa['TransTime'];
            $myMpesa->FirstName = $mpesa['FirstName'];
            $myMpesa->LastName = $mpesa['LastName'];
            $myMpesa->MiddleName = $mpesa['MiddleName'];
            $myMpesa->ThirdPartyTransID = $mpesa['ThirdPartyTransID'];
            $myMpesa->InvoiceNumber = $mpesa['InvoiceNumber'];
            $myMpesa->BillRefNumber = $mpesa['BillRefNumber'];
            $myMpesa->BusinessShortCode = $mpesa['BusinessShortCode'];
            $myMpesa->TransactionType = $mpesa['TransactionType'];
            if ($myMpesa->save()) {
                $transaction = Transaction::where('mpesa_id', $myMpesa->id)->first();
                if ($transaction == null) {
                    $transaction = new Transaction();
                }
                $vehicle = Vehicle::where('merchant_short_code', $myMpesa->BusinessShortCode)->first();
                if ($vehicle != null) {
                    $transaction->vehicle_id = $vehicle->id;
                }
                $transaction->mpesa_id = $myMpesa->id;
                $transaction->amount = $myMpesa->TransAmount;
                $transaction->trans_date = Carbon::parse($myMpesa->TransTime);
                $transaction->save();
            }
        }
        return response()->json(['mpesas' => "Mpesas imported successfully"]);
    }

    public function copyCashTransactions(Request $request)
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
        return response()->json(['cashes' => "Cashes imported successfully"]);
    }
    public function copySaccos(Request $request)
    {
        $url = "https://komiut.co.ke/api/saccos/copy";
        $json = json_decode(file_get_contents($url), true);
        foreach ($json["saccos"] as $sacco) {
            $newSacco = Sacco::where('name', $sacco['name'])->first();
            if ($newSacco == null) {
                $newSacco = new Sacco;
            }
            $newSacco->name = $sacco['name'];
            $newSacco->slogan = $sacco['sacco_motto'];
            $newSacco->phone = $sacco['customer_care_no'];
            if ($newSacco->save()) {
                if ($sacco['paybill'] != null && $sacco['consumer_secret'] != null && $sacco['consumer_key'] != null && $sacco['passkey'] != null) {
                    $mpesaPaymentSetting = MpesaPaymentSetting::where('sacco_id', $newSacco->id)->first();
                    if ($mpesaPaymentSetting == null) {
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
        return response()->json(['success' => 'Saccos Imported successfully']);
    }
    public function copySeats(Request $request)
    {
        $url = "https://komiut.co.ke/api/seats/copy";
        $json = json_decode(file_get_contents($url), true);
        foreach ($json["seats"] as $seat) {

            $myseat = Seat::where('name', $seat['name'])->first();
            if ($myseat == null) {
                $myseat = new Seat;
            }
            $myseat->name = $seat['name'];
            $myseat->seats = $seat['seats'];
            $myseat->rows = $seat['rows'];
            $myseat->columns = $seat['columns'];
            if ($myseat->save()) {
                foreach ($seat['seat_arrangement'] as $sa) {
                    $seatArrangement = SeatArrangement::where("name", $sa['seat_no'])->where('seat_id', $sa['seat_id'])->first();
                    if ($seatArrangement == null) {
                        $seatArrangement = new SeatArrangement;
                    }
                    $seatArrangement->name = $sa['seat_no'];
                    $seatArrangement->seat_id = $sa['seat_id'];
                    $seatArrangement->row = $sa['row'];
                    $seatArrangement->column = $sa["column"];
                    $seatArrangement->save();
                }
            }
        }
        return response()->json(['success' => 'Seats Imported successfully']);
    }

    public function copyVehicles(Request $request)
    {
        $url = "https://komiut.co.ke/api/vehicles/copy";
        $json = json_decode(file_get_contents($url), true);
        foreach ($json["buses"] as $bus) {
            $vehicle = Vehicle::where('plate', $bus['plate'])->first();
            if ($vehicle == null) {
                $vehicle = new Vehicle;
            }
            $sacco = Sacco::where('name', $bus['name'])->first();
            if ($sacco != null) {
                $vehicle->sacco_id = $sacco->id;
            }
            $vehicle->plate = $bus['plate'];
            $vehicle->fleet_no = $bus['fleet_no'];
            $vehicle->till_number = $bus['till'];
            $vehicle->merchant_short_code = $bus['merchant_short_code'];
            $vehicle->user_id = 1;
            $vehicle->status = 1;
            $seat = Seat::where('name', $bus['seat'])->first();
            if ($seat != null) {
                $vehicle->seat_id = $seat->id;
            }

            if ($vehicle->save()) {
                if ($sacco != null) {
                    $saccoUser = SaccoVehicle::where('vehicle_id', $vehicle->id)->where('sacco_id', $sacco->id)->where('end_date', null)->first();
                    if ($saccoUser == null) {
                        $saccoUser = new SaccoVehicle;
                        $saccoUser->vehicle_id = $vehicle->id;
                        $saccoUser->sacco_id = $sacco->id;
                        $saccoUser->start_date = $bus['created_at'];
                        $saccoUser->user_id = 1;
                        $saccoUser->status = 1;
                        $saccoUser->save();
                    }
                }
            }
        }
        return response()->json(['success' => 'Vehicles Imported successfully']);
    }

    public function copyUsers(Request $request)
    {
        $url = "https://komiut.co.ke/api/users/copy";
        $json = json_decode(file_get_contents($url), true);
        foreach ($json["buses"] as $user) {

            $myUser = User::where('email', $user['email'])->first();
            if ($myUser == null) {
                $myUser = new User;

                $myUser->firstname = $user['firstname'];
                $myUser->lastname = $user['lastname'];
                $phone = $user['phone'];
                if (strlen($phone) > 10) {
                    $phone = '0' . substr($user['phone'], 3);
                }
                $myUser->phone = $phone;
                $myUser->email = $user['email'];
                $myUser->dob = $user['date_of_birth'] != null ? $user['date_of_birth'] : Carbon::today()->subYears(18);
                $myUser->password = $user['password'];
                $myUser->gender_id = 1;
                $myUser->sacco_id = $user['sacco_id'] > 0 ? $user['sacco_id'] : null;
                $myUser->status = $user['status'];
                if (User::where('phone', $phone)->where('email', '<>', $user['email'])->count() == 0) {
                    $myUser->save();
                }
            }
            if ($user['sacco_id'] != null && $myUser->id != null) {
                $sacco = Sacco::where('name', $user['sacco'])->first();
                if ($sacco != null) {
                    $saccoUser = SaccoUser::where('user_id', $myUser->id)->where('sacco_id', $sacco->id)->where('end_date', null)->first();
                    if ($saccoUser == null) {
                        $saccoUser = new SaccoUser;
                        $saccoUser->user_id = $myUser->id;
                        $saccoUser->sacco_id = $sacco->id;
                        $saccoUser->start_date = $user['created_at'];
                        $saccoUser->created_by = 1;
                        $saccoUser->status = 1;
                        $saccoUser->save();
                    }
                }
            }

        }
        return response()->json(['success' => 'Users Imported successfully']);
    }

    public function copyRoles(Request $request){
        $url = "https://test.komiut.com/api/roles/copy/from";
        $json = json_decode(file_get_contents($url), true);
        foreach ($json["roles"] as $role) {

            $myRole = Role::where('name', $role['name'])->first();
            if ($myRole == null) {
                $myRole = new Role;

                $myRole->name = $role['name'];
                $myRole->guard_name = $role['guard_name'];
                if($myRole->where('name', $role['name'])->count() == 0) {
                    $myRole->save();
                }
            }
        }
        return response()->json(['success' => 'Roles Imported successfully']);
    }
    public function copyRolesFrom(Request $request){
        $roles = Role::get();
        return response()->json(['roles'=>$roles]);
    }
}
