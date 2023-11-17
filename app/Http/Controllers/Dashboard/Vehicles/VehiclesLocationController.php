<?php

namespace App\Http\Controllers\Dashboard\Vehicles;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Sacco;

class VehiclesLocationController extends Controller
{
    public function __construct(){
        $this->middleware('auth');
    }
    public function index(){
        $sacco = Sacco::find(Auth::user()->sacco_id);
        return view('dashboard.vehicles.locations', @compact('sacco'));
    }
}
