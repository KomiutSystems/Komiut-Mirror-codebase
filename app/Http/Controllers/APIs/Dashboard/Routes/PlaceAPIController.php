<?php

namespace App\Http\Controllers\APIs\Dashboard\Routes;

use App\Http\Controllers\Controller;
use App\Models\Place;
use Illuminate\Http\Request;

class PlaceAPIController extends Controller
{
    public function __construct(){
        $this->middleware('auth:api');
    }
    
    public function getPlaces(Request $request){
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $places = Place::where('name', 'LIKE', '%'.$request->search.'%')->skip($offset)->take(20)
        ->orderBy('name', 'ASC')->get();
        return response()->json(['places'=>$places]);
    }
}
