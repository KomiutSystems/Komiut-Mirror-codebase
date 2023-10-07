<?php

namespace App\Http\Controllers\Dashboard\Points;

use App\Http\Controllers\Controller;
use App\Models\Sacco;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PointsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    
    public function index()
    {
        $sacco = Sacco::find(Auth::user()->sacco_id);
        return view('dashboard.points.points', @compact('sacco'));
    }
}
