<?php

namespace App\Http\Controllers\APIs\Dashboard\Saccos;

use App\Http\Controllers\Controller;
use App\Models\Sacco;
use Illuminate\Http\Request;

class SaccoAPIController extends Controller
{
    public function __construct(){
        $this->middleware('auth:api');
    }
    
    public function getSaccos(Request $request){
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $saccos = Sacco::where('name', 'LIKE', '%'.$request->search.'%')->skip($offset)->take(20)
        ->orderBy('name', 'ASC')->get();
        return response()->json(['saccos'=>$saccos]);
    }
}
