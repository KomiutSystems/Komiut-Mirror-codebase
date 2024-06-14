<?php

namespace App\Http\Controllers\APIs\Dashboard\QRCode;

use App\Http\Controllers\Controller;
use App\Models\Point;
use App\Models\RedeemedPoint;
use App\Models\QrcodePayment;
use App\Models\SeatArrangement;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class QRCodeApiController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api');
    }

    public function getVehicle(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'till_number' => 'required|numeric',
            'seat_id' => 'nullable|integer',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 401);
        }
        $vehicle = Vehicle::with(['seat.seat_arrangements', 'sacco'])->where('till_number', $request->till_number)->first();
        $seat = SeatArrangement::find($request->seat_id);
        $points = Point::where('phone', auth()->user()->phone)->where('sacco_id', $vehicle->sacco_id)->first();
        if ($vehicle == null) {
            return response()->json(['error' => 'Till Number could not be found'], 400);
        }
        return response()->json(['vehicle' => $vehicle, 'seat' => $seat, 'points' => $points]);
    }

    public function getQRCodePayments(Request $request)
    {
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $from_date = $request->date != "" ? Carbon::parse($request->date) : Carbon::today();
        $to_date = $from_date->copy()->addDays(1);

        $payments = QrcodePayment::with(['vehicle.sacco', 'vehicle.seat', 'user.roles', 'user.gender'])
            ->whereBetween('created_at', [$from_date, $to_date]);
        if ($request->sacco > 0) {
            $payments = $payments->whereHas('vehicle', function ($query) use ($request) {
                $query->where('sacco_id', $request->sacco);
            });
        }
        if (!auth()->user()->can('View Transactions')) {
            $payments = $payments->where('user_id', Auth::user()->id);
        } else {
            $vehicles = explode(',', str_replace(']', '', str_replace('[', '', $request->vehicles)));
            $all_vehicles = [];

            foreach ($vehicles as $vehicle) {
                $v = trim($vehicle);
                if ($v != "") {
                    array_push($all_vehicles, trim($vehicle));
                }
            }
            if (count($all_vehicles) > 0) {
                $payments->whereIn('vehicle_id', $all_vehicles);
            }
        }
        $payments = $payments->where(function ($query) use ($request) {
            $query->orWhereHas('vehicle', function ($q) use ($request) {
                $q->where('plate', 'LIKE', '%' . $request->search . '%');
            })->orWhereHas('vehicle.sacco', function ($q) use ($request) {
                $q->where('name', 'LIKE', '%' . $request->search . '%');
            });
        })->orderBy('created_at', 'DESC')->skip($offset)->take(20)->get();
        return response()->json(['payments' => $payments]);
    }

    public function redeemPoints(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "vehicle_id" => "required|integer|exists:vehicles,id",
            "phone" => "required|digits:10",
            "seat_id" => "nullable|integer|exists:seat_arrangements,id",
            "user_id" => "nullable|integer|exists:users,id",
        ]);
        if ($validator->fails()) {
            return response()->json(["errors" => $validator->messages()], 400);
        }

        $points = Point::where('phone', $request->phone)->first();
        if ($points == null) {
            return response()->json(['error' => 'You do not have enough points to proceed!'], 401);
        }
        if ($points->points < 50) {
            return response()->json(['error' => 'You do not have enough points to proceed!'], 401);
        }
        $redeemedPoint = new RedeemedPoint();
        $redeemedPoint->point_id = $points->id;
        $redeemedPoint->redeemed_points = 50;
        $redeemedPoint->vehicle_id = $request->vehicle_id;
        if ($redeemedPoint->save()) {
            $points->points = $points->points - 50;
            $points->redeemed = $points->redeemed + 50;
            $points->save();
            return response()->json(['success' => 'Points Redeemed successfully']);
        } else {
            return response()->json(['error' => 'Unable to redeem points at the moment!'], 401);
        }
    }
}
