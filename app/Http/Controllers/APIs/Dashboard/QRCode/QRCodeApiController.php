<?php

namespace App\Http\Controllers\APIs\Dashboard\QRCode;

use App\Http\Controllers\Controller;
use App\Models\QrcodePayment;
use App\Models\SeatArrangement;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class QRCodeApiController extends Controller
{
    public function __construct(){
        $this->middleware('auth:api');
    }

    public function getVehicle(Request $request){
        $validator = Validator::make($request->all(), [
            'till_number' => 'required|numeric',
            'seat_id'=>'nullable|integer',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 401);
        }
        $vehicle = Vehicle::with(['seat.seat_arrangements', 'sacco'])->where('till_number', $request->till_number)->first();
        $seat = SeatArrangement::find($request->seat_id);
        if($vehicle == null){
            return response()->json(['error'=>'Till Number could not be found'], 400);
        }
        return response()->json(['vehicle'=>$vehicle, 'seat'=>$seat]);
    }

    public function getQRCodePayments(Request $request){
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
}
