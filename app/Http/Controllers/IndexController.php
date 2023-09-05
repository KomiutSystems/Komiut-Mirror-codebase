<?php

namespace App\Http\Controllers;

use App\Models\Gender;
use Illuminate\Http\Request;

class IndexController extends Controller
{
    public function index(){
        return view('index');
    }
    public function getGenders(Request $request){
        return json_encode(Gender::where('name', 'LIKE', '%'.$request->q.'%')
        ->orderBy('name', 'asc')->get());
    }
}
