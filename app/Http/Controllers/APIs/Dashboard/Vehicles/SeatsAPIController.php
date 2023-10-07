<?php

namespace App\Http\Controllers\APIs\Dashboard\Vehicles;

use App\Http\Controllers\Controller;
use App\Models\Seat;
use Illuminate\Http\Request;

class SeatsAPIController extends Controller
{

    public function __construct()
    {
        $this->middleware('auth:api');
    }

    public function getSeats(Request $request)
    {
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $seats = Seat::where('name', 'LIKE', '%' . $request->search . '%')->skip($offset)->take(20)
            ->orderBy('created_at', 'DESC')->get();
        return response()->json(['seats' => $seats]);
    }
}