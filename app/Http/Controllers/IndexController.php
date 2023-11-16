<?php

namespace App\Http\Controllers;

use App\Models\Gender;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class IndexController extends Controller
{
    public function index(){
        return view('index');
    }
    public function getGenders(Request $request){
        return json_encode(Gender::where('name', 'LIKE', '%'.$request->q.'%')
        ->orderBy('name', 'asc')->get());
    }
    public function checkLogin()
    {
        if (Auth::check()) {
            return response()->json(['loggedIn' => true]);
        } else {
            return response()->json(['loggedIn' => false]);
        }
    }
}
