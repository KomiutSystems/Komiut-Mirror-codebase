<?php

namespace App\Http\Controllers\APIs;

use App\Http\Controllers\Controller;
use App\Models\Cash;
use App\Models\Gender;
use App\Models\Mpesa;
use App\Models\MpesaPaymentSetting;
use App\Models\MpesaQrcodePayment;
use App\Models\Place;
use App\Models\Point;
use App\Models\PointSetting;
use App\Models\QrcodePayment;
use App\Models\Queue;
use App\Models\QueueStatus;
use App\Models\Route;
use App\Models\RouteStage;
use App\Models\Sacco;
use App\Models\SaccoRoute;
use App\Models\SaccoTerminus;
use App\Models\SaccoUser;
use App\Models\SaccoVehicle;
use App\Models\Seat;
use App\Models\SeatArrangement;
use App\Models\Terminus;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleUser;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use DB;

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
    public function copyTills()
    {
        $url = "https://payments.komiut.com/api/tills";
        $json = json_decode(file_get_contents($url), true);
        foreach ($json["tills"] as $till) {
            $mpesaPaymentSetting = MpesaPaymentSetting::where('business_short_code', $till['mpesa_setting']['shortcode'])->first();
            if ($mpesaPaymentSetting == null) {
                $mpesaPaymentSetting = new MpesaPaymentSetting();
            }
            $mpesaPaymentSetting->business_short_code = $till['mpesa_setting']['shortcode'];
            $mpesaPaymentSetting->consumer_key = $till['mpesa_setting']['consumer_key'];
            $mpesaPaymentSetting->consumer_secret = $till['mpesa_setting']['consumer_secret'];
            $mpesaPaymentSetting->pass_key = $till['mpesa_setting']['api_key'];
            $mpesaPaymentSetting->payment_mode = "CustomerBuyGoodsOnline";
            if ($mpesaPaymentSetting->save()) {
                $vehicle = Vehicle::where('merchant_short_code', $till['merchant_short_code'])->first();
                if ($vehicle != null) {
                    $vehicle->mpesa_payment_setting_id = $mpesaPaymentSetting->id;
                    $vehicle->save();
                }
            }
        }
        return response()->json(['mpesas' => "Tills Imported successfully"]);
    }

    public function copyMpesaTransactions()
    {
        $mpesa = Mpesa::orderBy('id', 'desc')->first();
        $mpesa_id = 0;
        if ($mpesa != null) {
            $mpesa_id = $mpesa->TransID;
        }
        $url = /*urlencode (*/ "https://komiut.co.ke/api/mpesas/copy?trans_id=" . urlencode($mpesa_id); //);
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
        $url = /*urlencode (*/ "https://komiut.co.ke/api/cashes/copy?trans_id=" . urlencode($trans_id); //);
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

    public function copyQrcodePayments(Request $request)
    {
        $url = "http://13.232.144.242/api/qrcode_payments/copy/from";
        $qrcode_payment = QrcodePayment::latest()->first();
        if ($qrcode_payment != null) {
            $url = "http://13.232.144.242/api/qrcode_payments/copy/from?created_at=" . urlencode($qrcode_payment->created_at);
        }
        //$created_at = Carbon::parse(urldecode(urlencode($qrcode_payment->created_at)));
        //return $created_at;
        $json = json_decode(file_get_contents($url), true);
        foreach ($json["qrcode_payments"] as $payment) {
            $vehicle = Vehicle::where('plate', $payment['vehicle']['plate'])->first();
            $seat_arrangement = null;
            if ($payment['seat_arrangement'] != null) {
                $seat_arrangement = SeatArrangement::where('name', $payment['seat_arrangement']['name'])->first();
            }
            ;
            $user = null;
            if ($payment['user'] != null) {
                $user = User::where('email', $payment['user']['email'])->first();
            }
            $created_at = Carbon::parse($payment['created_at']);
            if ($vehicle != null) {
                if (QrcodePayment::where('created_at', $created_at)->where('user_id', $user->id)->where('vehicle_id', $vehicle->id)->count() == 0) {
                    DB::table('qrcode_payments')->insert([
                        'vehicle_id' => $vehicle->id,
                        'amount' => $payment['amount'],
                        'seat_arrangement_id' => $seat_arrangement != null ? $seat_arrangement->id : null,
                        'user_id' => $user != null ? $user->id : null,
                        'status' => $payment['status'],
                        'created_at' => Carbon::parse($payment['created_at']),
                        'updated_at' => Carbon::parse($payment['updated_at']),
                    ]);
                }
            }
        }
        return response()->json(['qrcode_payments' => "Qrcode Payments imported successfully"]);
    }
    public function copyQrCodePaymentsFrom(Request $request)
    {
        $qrcode_payments = QrcodePayment::with(['vehicle', 'seat_arrangement.seat', 'user']);

        if ($request->created_at != null) {
            $start_date = Carbon::parse(urldecode($request->created_at));
            //\Log::info($start_date);
            //\Log::info($request->created_at);
            $qrcode_payments = $qrcode_payments->where('created_at', '>=', $start_date);
        }
        $qrcode_payments = $qrcode_payments->skip(0)->take(2000)->get();
        return response()->json(['qrcode_payments' => $qrcode_payments]);
    }
    public function copyMpesaQrcodePayments(Request $request)
    {
        $url = "http://13.232.144.242/api/mpesa_qrcode_payments/copy/from";
        $mpesa_qrcode_payment = MpesaQrcodePayment::latest()->first();
        if ($mpesa_qrcode_payment != null) {
            $url = "http://13.232.144.242/api/mpesa_qrcode_payments/copy/from?created_at=" . urlencode($mpesa_qrcode_payment->created_at);
        }
        //$created_at = Carbon::parse(urldecode(urlencode($qrcode_payment->created_at)));
        //return $created_at;
        $json = json_decode(file_get_contents($url), true);
        foreach ($json["mpesa_qrcode_payments"] as $payment) {
            if ($payment['qrcode_payment']) {
                $vehicle = Vehicle::where('plate', $payment['qrcode_payment']['vehicle']['plate'])->first();
                $seat_arrangement = null;
                if ($payment['qrcode_payment']['seat_arrangement'] != null) {
                    $seat_arrangement = SeatArrangement::where('name', $payment['qrcode_payment']['seat_arrangement']['name'])->first();
                }
                ;
                $user = null;
                if ($payment['qrcode_payment']['user'] != null) {
                    $user = User::where('email', $payment['qrcode_payment']['user']['email'])->first();
                }
                $created_at = Carbon::parse($payment['qrcode_payment']['created_at']);
                if ($vehicle != null) {
                    if (QrcodePayment::where('created_at', $created_at)->where('user_id', $user != null ? $user->id : null)->where('vehicle_id', $vehicle->id)->count() == 0) {
                        $id = DB::table('qrcode_payments')->insertGetId([
                            'vehicle_id' => $vehicle->id,
                            'amount' => $payment['qrcode_payment']['amount'],
                            'seat_arrangement_id' => $seat_arrangement != null ? $seat_arrangement->id : null,
                            'user_id' => $user != null ? $user->id : null,
                            'status' => $payment['qrcode_payment']['status'],
                            'created_at' => Carbon::parse($payment['qrcode_payment']['created_at']),
                            'updated_at' => Carbon::parse($payment['qrcode_payment']['updated_at']),
                        ]);

                        if ($id > 0) {
                            DB::table('mpesa_qrcode_payments')->insert([
                                [
                                    "transid" => $payment['transid'],
                                    "name" => $payment['name'],
                                    "amount" => $payment['amount'],
                                    "points" => $payment['points'],
                                    "phone" => $payment["phone"],
                                    "transdate" => $payment['transdate'],
                                    "qrcode_payment_id" => $id,
                                    "callback" => $payment['callback'],
                                    "redeemed" => $payment['redeemed'],
                                    "created_at" => Carbon::parse($payment['created_at']),
                                    'updated_at' => Carbon::parse($payment['updated_at'])
                                ]
                            ]);
                        }
                    }
                }
            }
        }
        return response()->json(['mpesa_qrcode_payments' => "Mpesa Qrcode Payments imported successfully"]);
    }
    public function copyPoints(Request $request)
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
        return response()->json(['success' => "Points imported successfully"]);
    }

    public function copyPointsFrom(Request $request)
    {
        $points = Point::with(['user', 'sacco']);
        if ($request->created_at != null) {
            $start_date = Carbon::parse(urldecode($request->created_at));
            $points = $points->where('created_at', '>=', $start_date);
        }
        $points = $points->skip(0)->take(1000)->get();
        return response()->json(['points' => $points]);
    }
    public function copyPointSettings(Request $request)
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
            if ($point['sacco'] != null) {
                $sacco = Sacco::where('name', $point_setting['sacco']['name'])->first();
            }
            $role = null;
            if ($point['sacco'] != null) {
                $role = Role::where('name', $point_setting['role']['name'])->first();
            }
            if (
                PointSetting::where('amount', $point_setting['amount'])
                    ->where('amount', $point_setting['amount'])->
                    where('items', $point_setting['items'])->
                    where('points_on', $point_setting['points_on'])->
                    where('points_type', $point_setting['points_type'])->
                    where('role', $role != null ? $role->id : null)->
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
        return response()->json(['success' => "Point settings imported successfully"]);
    }

    public function copyPointSettingsFrom(Request $request)
    {
        $point_settings = PointSetting::with(['sacco', 'role']);
        if ($request->created_at != null) {
            $start_date = Carbon::parse(urldecode($request->created_at));
            $point_settings = $point_settings->where('created_at', '>=', $start_date);
        }
        $point_settings = $point_settings->skip(0)->take(1000)->get();
        return response()->json(['point_settings' => $point_settings]);
    }
    public function copyMpesaQrCodePaymentsFrom(Request $request)
    {
        $mpesa_qrcode_payments = MpesaQrcodePayment::with(['qrcode_payment.vehicle', 'qrcode_payment.seat_arrangement.seat', 'qrcode_payment.user']);

        if ($request->created_at != null) {
            $start_date = Carbon::parse(urldecode($request->created_at));
            //\Log::info($start_date);
            //\Log::info($request->created_at);
            $mpesa_qrcode_payments = $mpesa_qrcode_payments->where('created_at', '>=', $start_date);
        }
        $mpesa_qrcode_payments = $mpesa_qrcode_payments->skip(0)->take(1000)->get();
        return response()->json(['mpesa_qrcode_payments' => $mpesa_qrcode_payments]);
    }
    public function copyQueues(Request $request)
    {
        $url = "http://13.232.144.242/api/queues/copy/from";
        $queue = Queue::latest()->first();
        if ($queue != null) {
            $url = "http://13.232.144.242/api/queues/copy/from?created_at=" . urlencode($queue->created_at);
        }
        $json = json_decode(file_get_contents($url), true);
        foreach ($json["queues"] as $queue) {
            $vehicle = Vehicle::where('plate', $queue['vehicle']['plate'])->first();
            $terminus = Terminus::where('name', $queue['terminus'] != null ? $queue['terminus']['name'] : null)->first();
            $queue_status = QueueStatus::where('name', $queue['queue_status']['name'])->where('status', $queue['queue_status']['status'])->first();
            $from = Place::where('name', $queue['route']['from']['name'])->first();
            $to = Place::where('name', $queue['route']['to']['name'])->first();
            $route = Route::where('from_id', $from->id)->where('to_id', $to->id)->first();
            $user = User::where('email', $queue['user']['email'])->first();
            if ($terminus == null) {
                $terminus = Terminus::where('place_id', $from->id)->first();
                if ($terminus == null) {
                    $terminus = Terminus::first();
                }
            }
            if (
                Queue::where('vehicle_id', $vehicle->id)->where('route_id', $route->id)->where('queue_status_id', $queue_status->id)
                    ->where('terminus_id', $terminus->id)->where('user_id', $user->id)->where('created_at', Carbon::parse($queue['created_at']))->count() <= 0
            ) {
                $id = DB::table("queues")->insertGetId([
                    "queue_number" => $queue['queue_number'],
                    "vehicle_id" => $vehicle->id,
                    "terminus_id" => $terminus->id,
                    "queue_status_id" => $queue_status->id,
                    "route_id" => $route->id,
                    "user_id" => $user->id,
                    'amount' => $queue['amount'],
                    'schedule_time' => $queue['schedule_time'] != null ? Carbon::parse($queue['schedule_time']) : null,
                    'start_time' => $queue['start_time'] != null ? Carbon::parse($queue['start_time']) : null,
                    'end_time' => $queue['end_time'] != null ? Carbon::parse($queue['end_time']) : null,
                    'queue_type' => $queue['queue_type'],
                    "created_at" => $queue['created_at'] != null ? Carbon::parse($queue['created_at']) : Carbon::parse($queue['start_time']),
                    "updated_at" => Carbon::parse($queue['updated_at'])
                ]);
                if ($id > 0) {
                    foreach ($queue['bookings'] as $booking) {
                        $booking_from = null;
                        if ($booking['from'] != null) {
                            $booking_from = Place::where('name', $booking['from']['name'])->first();
                        }
                        $booking_to = null;
                        if ($booking['to'] != null) {
                            $booking_to = Place::where('name', $booking['to']['name'])->first();
                        }
                        $user = null;
                        if ($booking['user'] != null) {
                            $user = User::where('email', $booking['user']['email'])->first();
                        }
                        $creator = null;
                        if ($booking['creator'] != null) {
                            $creator = User::where('email', $booking['creator']['email'])->first();
                        }
                        $booking_id = DB::table('bookings')->insertGetId([
                            "name" => $booking['name'],
                            "phone" => $booking['phone'],
                            "passengers" => $booking['passengers'],
                            "user_id" => $user != null ? $user->id : null,
                            "queue_id" => $id,
                            'from_id' => $booking_from != null ? $booking_from->id : $from->id,
                            'to_id' => $booking_to != null ? $booking_to->id : $to->id,
                            "amount" => $booking['amount'],
                            'boarded' => $booking['boarded'],
                            'paid' => $booking['paid'],
                            "start_time" => $booking["start_time"] != null ? Carbon::parse($booking["start_time"]) : null,
                            "stop_time" => $booking["stop_time"] != null ? Carbon::parse($booking["stop_time"]) : null,
                            'created_by' => $creator != null ? $creator->id : 1,
                            'status' => $booking['status'],
                            'created_at' => Carbon::parse($booking['created_at']),
                            'updated_at' => Carbon::parse($booking['updated_at'])
                        ]);
                        if ($booking_id > 0) {
                            foreach ($booking['seats'] as $seat) {
                                $my_seat = null;
                                if ($seat['seat'] != null) {
                                    if ($seat['seat']['seat'] != null) {
                                        $my_seat = Seat::where('name', $seat['seat']['seat']['name'])->first();
                                    }
                                }
                                $seat_arrangement = null;
                                if ($seat['seat'] != null && $my_seat != null) {
                                    $seat_arrangement = SeatArrangement::where('name', $seat['seat']['name'])
                                        ->where('seat_id', $my_seat->id)->first();
                                }
                                if ($seat_arrangement != null) {
                                    DB::table('seat_bookings')->insert([
                                        "seat_id" => $my_seat->id,
                                        "booking_id" => $booking_id,
                                        "status" => $seat['status'],
                                        'created_at' => Carbon::parse($seat['created_at']),
                                        'updated_at' => Carbon::parse($seat['updated_at'])
                                    ]);
                                }
                            }

                            foreach ($booking['mpesa_booking_callbacks'] as $callback) {
                                DB::table("mpesa_booking_callbacks")->insert([
                                    "transid" => $callback['transid'],
                                    "name" => $callback['name'],
                                    "amount" => $callback['amount'],
                                    "points" => $callback['points'],
                                    "phone" => $callback['phone'],
                                    "transdate" => Carbon::parse($callback['transdate']),
                                    "booking_id" => $booking_id,
                                    "callback" => $callback['callback'],
                                    "redeemed" => $callback['redeemed'],
                                    'created_at' => Carbon::parse($callback['created_at']),
                                    'updated_at' => Carbon::parse($callback['updated_at'])
                                ]);
                            }
                        }
                    }
                }
            }
        }
        return response()->json(['success' => 'Queues Imported successfully']);
    }
    public function copyQueuesFrom(Request $request)
    {
        $queues = Queue::with(['user', 'terminus', 'queue_status', 'vehicle', 'route.from', 'route.to', 'bookings.from', 'bookings.to', 'bookings.creator', 'bookings.user', 'bookings.seats.seat.seat', 'bookings.mpesa_booking_callbacks']);
        if ($request->created_at != null) {
            $start_date = Carbon::parse(urldecode($request->created_at));
            $queues = $queues->where('created_at', '>=', $start_date);
        }
        $queues = $queues->skip(0)->take(1000)->get();
        return response()->json(['queues' => $queues]);
    }
    public function copyVehicleUsers(Request $request)
    {
        $url = "https://test.komiut.com/api/vehicle_users/copy/from";
        $json = json_decode(file_get_contents($url), true);
        foreach ($json["vehicle_users"] as $vehicleUser) {
            //"user_id","vehicle_id","sacco_id", 'start_date','end_date', 'status'
            $vehicle = Vehicle::where('plate', $vehicleUser['vehicle']['plate'])->first();
            if ($vehicleUser['sacco'] != null) {
                $sacco = Sacco::where('name', $vehicleUser['sacco']['name'])->first();
            }
            if ($vehicleUser['user'] != null) {
                $user = User::where('email', $vehicleUser['user']['email'])->first();
                $newVehicleUser = VehicleUser::where('user_id', $user->id);
                if ($vehicleUser['sacco'] != null) {
                    $newVehicleUser = $newVehicleUser->where('sacco_id', $sacco->id);
                }
                $newVehicleUser = $newVehicleUser->where('vehicle_id', $vehicle->id)->first();
                if ($newVehicleUser == null) {
                    $newVehicleUser = new VehicleUser;
                }
                $newVehicleUser->user_id = $user->id;
                if ($vehicleUser['sacco'] != null) {
                    $newVehicleUser->sacco_id = $sacco->id;
                }
                $newVehicleUser->vehicle_id = $vehicle->id;
                $newVehicleUser->start_date = $vehicleUser['start_date'];
                $newVehicleUser->end_date = $vehicleUser['end_date'];
                $newVehicleUser->status = $vehicleUser['status'];
                $newVehicleUser->save();
            }
        }
        return response()->json(['success' => 'Vehicle Users Imported successfully']);
    }
    public function copyVehicleUsersFrom(Request $request)
    {
        $vehicle_users = VehicleUser::with(['user', 'sacco', 'vehicle'])->get();
        return response()->json(['vehicle_users' => $vehicle_users]);
    }
    public function copySaccoRoutes(Request $request)
    {
        $url = "https://test.komiut.com/api/sacco_routes/copy/from";
        $json = json_decode(file_get_contents($url), true);
        foreach ($json["sacco_routes"] as $saccoRoute) {
            //'user_id', 'route_id', 'sacco_id', 'amount', 'min_amount', 'status'
            $from = Place::where('name', $saccoRoute['route']['from']['name'])->first();
            $to = Place::where('name', $saccoRoute['route']['to']['name'])->first();
            if ($saccoRoute['sacco'] != null) {
                $sacco = Sacco::where('name', $saccoRoute['sacco']['name'])->first();
            }
            $user = User::where('email', $saccoRoute['user']['email'])->first();
            $route = Route::where('from_id', $from->id)->where('to_id', $to->id)
                ->first();
            if ($sacco != null) {

                $newSaccoRoute = SaccoRoute::where('route_id', $route->id)->where('sacco_id', $sacco->id)->first();
                if ($newSaccoRoute == null) {
                    $newSaccoRoute = new SaccoRoute;
                }
                $newSaccoRoute->route_id = $route->id;
                $newSaccoRoute->sacco_id = $sacco->id;
                $newSaccoRoute->user_id = $user->id;
                $newSaccoRoute->amount = $saccoRoute['amount'] != null ? $saccoRoute['amount'] : 20;
                $newSaccoRoute->min_amount = $saccoRoute['min_amount'] != null ? $saccoRoute['min_amount'] : 20;
                $newSaccoRoute->status = $saccoRoute['status'];
                $newSaccoRoute->save();
            }
        }
        return response()->json(['success' => 'Sacco Routes Imported successfully']);
    }
    public function copySaccoRoutesFrom(Request $request)
    {
        $sacco_routes = SaccoRoute::with(['user', 'sacco', 'route.from', 'route.to'])->get();
        return response()->json(['sacco_routes' => $sacco_routes]);
    }

    public function copyRouteStages(Request $request)
    {
        $url = "https://test.komiut.com/api/route_stages/copy/from";
        $json = json_decode(file_get_contents($url), true);
        foreach ($json["route_stages"] as $routeStage) {
            //'route_id', 'place_id', 'longitude', 'latitude', 'distance','status'
            $from = Place::where('name', $routeStage['route']['from']['name'])->first();
            $to = Place::where('name', $routeStage['route']['to']['name'])->first();
            $place = Place::where('name', $routeStage['place']['name'])->first();
            $route = Route::where('from_id', $from->id)->where('to_id', $to->id)
                ->first();

            $newRouteStage = RouteStage::where('place_id', $place->id)->where('route_id', $route->id)->first();
            if ($newRouteStage == null) {
                $newRouteStage = new RouteStage;
            }
            $newRouteStage->route_id = $route->id;
            $newRouteStage->place_id = $place->id;
            $newRouteStage->longitude = $routeStage['longitude'];
            $newRouteStage->latitude = $routeStage['latitude'];
            $newRouteStage->distance = $routeStage['distance'];
            $newRouteStage->status = $routeStage['status'];
            $newRouteStage->save();
        }
        return response()->json(['success' => 'Route Stages Imported successfully']);
    }
    public function copyRouteStagesFrom(Request $request)
    {
        $route_stages = RouteStage::with(['place', 'route.from', 'route.to'])->get();
        return response()->json(['route_stages' => $route_stages]);
    }
    public function copyQueueStatuses(Request $request)
    {
        $url = "https://test.komiut.com/api/queue_statuses/copy/from";
        $json = json_decode(file_get_contents($url), true);
        foreach ($json["queue_statuses"] as $queueStatus) {
            $newQueueStatus = QueueStatus::where('name', $queueStatus['name'])->first();
            if ($newQueueStatus == null) {
                $newQueueStatus = new QueueStatus;
            }
            $newQueueStatus->name = $queueStatus['name'];
            $newQueueStatus->status = $queueStatus['status'];
            $newQueueStatus->active = $queueStatus['active'];
            $newQueueStatus->save();
        }
        return response()->json(['success' => 'Queue Statuses Imported successfully']);
    }
    public function copyQueueStatusesFrom(Request $request)
    {
        $queue_statuses = QueueStatus::get();
        return response()->json(['queue_statuses' => $queue_statuses]);
    }
    public function copySaccoTermini(Request $request)
    {
        $url = "https://test.komiut.com/api/saccos/termini/copy/from";
        $json = json_decode(file_get_contents($url), true);
        foreach ($json["sacco_termini"] as $saccoTerminus) {
            $sacco = Sacco::where('name', $saccoTerminus['sacco']['name'])->first();
            $user = User::where('email', $saccoTerminus['user']['email'])->first();
            $terminus = Terminus::where('name', $saccoTerminus['terminus']['name'])->first();

            $newSaccoTerminus = SaccoTerminus::where('terminus_id', $terminus->id)
                ->where('sacco_id', $sacco->id)->first();
            if ($newSaccoTerminus == null) {
                $newSaccoTerminus = new SaccoTerminus;
            }
            $newSaccoTerminus->sacco_id = $sacco->id;
            $newSaccoTerminus->user_id = $user->id;
            $newSaccoTerminus->terminus_id = $terminus->id;
            $newSaccoTerminus->status = $saccoTerminus['status'];
            $newSaccoTerminus->save();
        }
        return response()->json(['success' => 'Sacco Terminus Imported successfully']);
    }
    public function copySaccoTerminiFrom(Request $request)
    {
        $sacco_termini = SaccoTerminus::with(['sacco', 'user', 'terminus'])->get();
        return response()->json(['sacco_termini' => $sacco_termini]);
    }
    public function copyTermini(Request $request)
    {
        $url = "https://test.komiut.com/api/termini/copy/from";
        $json = json_decode(file_get_contents($url), true);
        foreach ($json["termini"] as $terminus) {
            $place = Place::where('name', $terminus['place']['name'])->first();

            $newTerminus = Terminus::where('name', $place->name)->where('place_id', $place->id)->first();
            if ($newTerminus == null) {
                $newTerminus = new Terminus;
            }
            $newTerminus->name = $terminus['name'];
            $newTerminus->place_id = $place->id;
            $newTerminus->longitude = $terminus['longitude'];
            $newTerminus->latitude = $terminus['latitude'];
            $newTerminus->status = $terminus['status'];
            $newTerminus->save();
        }
        return response()->json(['success' => 'Terminus Imported successfully']);
    }
    public function copyTerminiFrom(Request $request)
    {
        $termini = Terminus::with(['place'])->get();
        return response()->json(['termini' => $termini]);
    }
    public function copyRoutes(Request $request)
    {
        $url = "https://test.komiut.com/api/routes/copy/from";
        $json = json_decode(file_get_contents($url), true);
        foreach ($json["routes"] as $route) {
            $from = Place::where('name', $route['from']['name'])->first();
            $to = Place::where('name', $route['to']['name'])->first();

            $newRoute = Route::where('from_id', $from->id)->where('to_id', $to->id)->first();
            if ($newRoute == null) {
                $newRoute = new Route;
            }
            $newRoute->name = $route['name'] != "" ? $route['name'] : $from->name . " - " . $to->name;
            $newRoute->from_id = $from->id;
            $newRoute->to_id = $to->id;
            $newRoute->status = $route['status'];
            $newRoute->save();
        }
        return response()->json(['success' => 'Routes Imported successfully']);
    }
    public function copyRoutesFrom(Request $request)
    {
        $routes = Route::with(['from', 'to'])->get();
        return response()->json(['routes' => $routes]);
    }
    public function copyPlaces(Request $request)
    {
        $url = "https://test.komiut.com/api/places/copy/from";
        $json = json_decode(file_get_contents($url), true);
        foreach ($json["places"] as $place) {
            $newPlace = Place::where('name', $place['name'])->first();
            if ($newPlace == null) {
                $newPlace = new Place;
            }
            $newPlace->name = $place['name'];
            $newPlace->county_name = $place['county_name'];
            $newPlace->longitude = $place['longitude'];
            $newPlace->latitude = $place['latitude'];
            $newPlace->status = $place['status'];
            $newPlace->save();
        }
        return response()->json(['success' => 'Places Imported successfully']);
    }
    public function copyPlacesFrom(Request $request)
    {
        $places = Place::get();
        return response()->json(['places' => $places]);
    }
    public function copySaccos(Request $request)
    {
        $url = "https://test.komiut.com/api/saccos/copy/from";
        $json = json_decode(file_get_contents($url), true);
        foreach ($json["saccos"] as $sacco) {
            $newSacco = Sacco::where('name', $sacco['name'])->first();
            if ($newSacco == null) {
                $newSacco = new Sacco;
            }
            $newSacco->name = $sacco['name'];
            $newSacco->slogan = $sacco['slogan'];
            $newSacco->phone = $sacco['phone'];
            $newSacco->status = $sacco['status'];
            if ($newSacco->save()) {
                if ($sacco['mpesa_payment'] != null) {
                    $mpesaPaymentSetting = MpesaPaymentSetting::where('sacco_id', $newSacco->id)->first();
                    if ($mpesaPaymentSetting == null) {
                        $mpesaPaymentSetting = new MpesaPaymentSetting;
                    }
                    $mpesaPaymentSetting->sacco_id = $newSacco->id;
                    $mpesaPaymentSetting->consumer_key = $sacco['mpesa_payment']['consumer_key'];
                    $mpesaPaymentSetting->consumer_secret = $sacco['mpesa_payment']['consumer_secret'];
                    $mpesaPaymentSetting->pass_key = $sacco['mpesa_payment']['pass_key'];
                    $mpesaPaymentSetting->business_short_code = $sacco['mpesa_payment']['business_short_code'];
                    $mpesaPaymentSetting->payment_mode = $sacco['mpesa_payment']['payment_mode'];
                    $mpesaPaymentSetting->is_live = $sacco['mpesa_payment']['is_live'];
                    $mpesaPaymentSetting->status = $sacco['mpesa_payment']['status'];
                    $mpesaPaymentSetting->save();
                }
            }
        }
        return response()->json(['success' => 'Saccos Imported successfully']);
    }
    public function copySaccosFrom(Request $request)
    {
        $saccos = Sacco::with(['mpesa_payment'])->get();
        return response()->json(['saccos' => $saccos]);
    }
    public function copyVehicles(Request $request)
    {
        $url = "https://test.komiut.com/api/vehicles/copy/from";
        $json = json_decode(file_get_contents($url), true);
        foreach ($json["vehicles"] as $vehicle) {
            $sacco = null;
            $user = null;
            $seat = null;
            if ($vehicle['sacco'] != null) {
                $sacco = Sacco::where('name', $vehicle['sacco']['name'])->first();
            }
            if ($vehicle['user'] != null) {
                $user = User::where('email', $vehicle['user']['email'])->first();
            }
            if ($vehicle['seat'] != null) {
                $seat = Seat::where('name', $vehicle['seat']['name'])->first();
            }
            $vehicle1 = Vehicle::where('plate', $vehicle['plate'])->first();
            if ($vehicle1 == null) {
                $vehicle1 = new Vehicle;
            }
            if ($sacco != null) {
                $vehicle1->sacco_id = $sacco->id;
            }
            if ($user != null) {
                $vehicle1->user_id = $user->id;
            } else {
                $vehicle1->user_id = 1;
            }
            $vehicle1->plate = $vehicle['plate'];
            $vehicle1->fleet_no = $vehicle['fleet_no'];
            $vehicle1->till_number = $vehicle['till_number'];
            $vehicle1->merchant_short_code = $vehicle['merchant_short_code'];
            $vehicle1->status = $vehicle['status'];
            if ($seat != null) {
                $vehicle1->seat_id = $seat->id;
            } else {
                $vehicle1->seat_id = 1;
            }

            if ($vehicle1->save()) {
                if ($sacco != null) {
                    $saccoUser = SaccoVehicle::where('vehicle_id', $vehicle1->id)
                        ->where('sacco_id', $sacco->id)->where('end_date', null)->first();
                    if ($saccoUser == null) {
                        $saccoUser = new SaccoVehicle;
                        $saccoUser->vehicle_id = $vehicle1->id;
                        $saccoUser->sacco_id = $sacco->id;
                        $saccoUser->start_date = Carbon::parse($vehicle['created_at']);
                        $saccoUser->user_id = $user != null ? $user->id : 1;
                        $saccoUser->status = 1;
                        $saccoUser->save();
                    }
                }
            }
        }
        return response()->json(['success' => 'Vehicles Imported successfully']);
    }
    public function copyVehiclesFrom(Request $request)
    {
        $vehicles = Vehicle::with(['seat', 'user', 'sacco'])->get();
        return response()->json(['vehicles' => $vehicles]);
    }
    public function copySeats(Request $request)
    {
        $url = "https://test.komiut.com/api/seats/copy/from";
        $json = json_decode(file_get_contents($url), true);
        foreach ($json["seat_arrangements"] as $seat_arrangement) {
            $seat = Seat::where('name', $seat_arrangement['seat']['name'])->first();
            if ($seat == null) {
                $seat = new Seat;
                $seat->name = $seat_arrangement['seat']['name'];
                $seat->seats = $seat_arrangement['seat']['seats'];
                $seat->rows = $seat_arrangement['seat']["rows"];
                $seat->columns = $seat_arrangement['seat']["columns"];
                $seat->status = $seat_arrangement['seat']["status"];
                $seat->save();
            }
            $seat_arrangement1 = SeatArrangement::where('seat_id', $seat_arrangement['seat_id'])
                ->where('name', $seat_arrangement['name'])->first();
            if ($seat_arrangement1 == null) {
                $seat_arrangement1 = new SeatArrangement;
            }
            $seat_arrangement1->seat_id = $seat_arrangement['seat_id'];
            $seat_arrangement1->row = $seat_arrangement['row'];
            $seat_arrangement1->column = $seat_arrangement['column'];
            $seat_arrangement1->name = $seat_arrangement['name'];
            $seat_arrangement1->status = $seat_arrangement['status'];
            $seat_arrangement1->save();
        }
        return response()->json(['success' => 'Seats Arrangement Imported successfully']);
    }
    public function copySeatsFrom(Request $request)
    {
        $seat_arrangements = SeatArrangement::with('seat')->get();
        return response()->json(['seat_arrangements' => $seat_arrangements]);
    }
    public function copyUserPasswords(Request $request)
    {
        $url = "https://test.komiut.com/api/users/passwords/copy/from";
        $json = json_decode(file_get_contents($url), true);
        foreach ($json["users"] as $user) {
            $myUser = User::where('email', $user['email'])->first();
            $myUser->password = $user['password'];
            $myUser->save();
        }
        return response()->json(['success' => 'User Passwords Imported successfully']);
    }
    public function copyUserPasswordsFrom(Request $request)
    {
        $users = DB::table('users')->select('email', 'password')->get();
        return response()->json(['users' => $users]);
    }

    public function copyUsers(Request $request)
    {
        $url = "https://test.komiut.com/api/users/copy/from";
        $json = json_decode(file_get_contents($url), true);
        foreach ($json["users"] as $user) {

            $myUser = User::where('email', $user['email'])->first();
            if ($myUser == null) {
                $myUser = new User;

                $myUser->firstname = $user['firstname'];
                $myUser->lastname = $user['lastname'];
                $myUser->phone = $user['phone'];
                $myUser->email = $user['email'];
                $myUser->dob = $user['dob'] != null ? $user['dob'] : Carbon::today()->subYears(18);
                $myUser->password = Hash::make('12345');
                if ($user['gender_id'] > 0) {
                    $gender = Gender::where('name', $user['gender']['name'])->first();
                    if ($gender == null) {
                        $gender = new Gender;
                        $gender->name = $user['gender']['name'];
                        $gender->status = $user['gender']['status'];
                        $gender->save();
                    }
                    $myUser->gender_id = $gender->id;
                }
                if ($user['sacco_id'] > 0 && $user['sacco'] != null) {
                    $sacco = Sacco::where('name', $user['sacco']['name'])->first();
                    if ($sacco == null) {
                        $sacco = new Sacco;
                        $sacco->name = $user['sacco']['name'];
                        $sacco->slogan = $user['sacco']['slogan'];
                        $sacco->phone = $user['sacco']['phone'];
                        $sacco->phone = $user['sacco']['status'];
                        $sacco->save();
                    }
                    $myUser->sacco_id = $sacco->id;
                }

                $role = Role::where('name', $user['roles'][0]['name'])->first();
                if ($role == null) {
                    $role = new Role;
                    $role->name = $user['roles'][0]['name'];
                    $role->guard_name = $user['roles'][0]['guard_name'];
                    $role->save();
                }
                $myUser->status = $user['status'];
                if (User::where('phone', $user['phone'])->where('email', '<>', $user['email'])->count() == 0) {
                    $myUser->save();
                    $myUser->syncRoles($role);
                }

                if ($user['sacco_id'] > 0 && $myUser->id != null && $user['sacco'] != null) {
                    $sacco = Sacco::where('name', $user['sacco']['name'])->first();
                    if ($sacco != null) {
                        $saccoUser = SaccoUser::where('user_id', $myUser->id)->where('sacco_id', $sacco->id)->where('end_date', null)->first();
                        if ($saccoUser == null) {
                            $saccoUser = new SaccoUser;
                            $saccoUser->user_id = $myUser->id;
                            $saccoUser->sacco_id = $sacco->id;
                            $saccoUser->start_date = Carbon::parse($user['created_at']);
                            $saccoUser->created_by = 1;
                            $saccoUser->status = 1;
                            $saccoUser->save();
                        }
                    }
                }
            }
        }
        return response()->json(['success' => 'Users Imported successfully']);
    }
    public function copyUsersFrom(Request $request)
    {
        $users = User::with(['roles', 'gender', 'sacco'])->get();
        return response()->json(['users' => $users]);
    }


    public function copyRoles(Request $request)
    {
        $url = "https://test.komiut.com/api/roles/copy/from";
        $json = json_decode(file_get_contents($url), true);
        foreach ($json["roles"] as $role) {

            $myRole = Role::where('name', $role['name'])->first();
            if ($myRole == null) {
                $myRole = new Role;

                $myRole->name = $role['name'];
                $myRole->guard_name = $role['guard_name'];
                if ($myRole->where('name', $role['name'])->count() == 0) {
                    $myRole->save();
                }
            }
        }
        return response()->json(['success' => 'Roles Imported successfully']);
    }
    public function copyRolesFrom(Request $request)
    {
        $roles = Role::get();
        return response()->json(['roles' => $roles]);
    }
}
