<?php

namespace App\Http\Controllers\Dashboard;
use App\Http\Controllers\Controller;

class HomeController extends Controller
{
    public function __construct(){
        $this->middleware('auth');
    }

    public function index(){
        return redirect()->to('dashboard/home');
    }
}
