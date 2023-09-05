<?php

namespace App\Http\Controllers\Dashboard\Vehicles;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class VehiclesLocationController extends Controller
{
    public function __construct(){
        $this->middleware('auth');
    }
    public function index(){
        return view('dashboard.vehicles.locations');
    }
}
