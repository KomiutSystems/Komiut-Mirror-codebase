<?php

namespace App\Http\Controllers\APIs\Dashboard\Settings;

use App\Http\Controllers\Controller;
use App\Models\Gender;
use App\Services\Sql\LikeSql;
use Illuminate\Http\Request;

class GenderAPIController extends Controller
{

    public function __construct(){
        $this->middleware('auth:sanctum');
    }

    public function getGenders(Request $request){

        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;

        $genders = Gender::when(filled($request->search), fn ($q) => $q->where('name', LikeSql::op(), '%'.$request->search.'%'))
        ->orderBy('name', 'ASC')->skip($offset)->take(20)->get();
        return response()->json(['genders'=>$genders]);
    }
}
