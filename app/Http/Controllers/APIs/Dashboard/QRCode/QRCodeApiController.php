<?php

namespace App\Http\Controllers\APIs\Dashboard\QRCode;

use App\Http\Controllers\Controller;
use App\Models\SeatArrangement;
use App\Models\Vehicle;
use Illuminate\Http\Request;
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
            return response()->json(['error'=>'Till Numbe could not be found'], 400);
        }
        return response()->json(['vehicle'=>$vehicle, 'seat'=>$seat]);
    }
}
