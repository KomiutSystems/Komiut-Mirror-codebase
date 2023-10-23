<?php

namespace App\Http\Controllers\Dashboard\Routes;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class TerminusUserController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(['permission:View Termini Users']);
    }
    public function index(){
        return view('dashboard/routes/termini_users');
    }
}
