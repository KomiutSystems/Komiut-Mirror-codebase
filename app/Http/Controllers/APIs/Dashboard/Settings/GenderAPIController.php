<?php

namespace App\Http\Controllers\APIs\Dashboard\Settings;

use App\Http\Controllers\Controller;
use App\Models\Gender;
use Illuminate\Http\Request;

class GenderAPIController extends Controller
{
    
    public function __construct(){
        $this->middleware('auth:api');
    }
    
    public function getGenders(Request $request){
        
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;

        $genders = Gender::where('name', 'LIKE', '%'.$request->search.'%')
        ->orderBy('name', 'ASC')->skip($offset)->take(20)->get();
        return response()->json(['genders'=>$genders]);
    }
}
