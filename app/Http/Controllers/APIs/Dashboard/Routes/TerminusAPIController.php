<?php

namespace App\Http\Controllers\APIs\Dashboard\Routes;

use App\Http\Controllers\Controller;
use App\Models\Terminus;
use Illuminate\Http\Request;

class TerminusAPIController extends Controller
{
    public function __construct(){
        $this->middleware('auth:api');
    }
    
    public function getTermini(Request $request){
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $termini = Terminus::with('place')->where('name', 'LIKE', '%'.$request->search.'%')
        ->skip($offset)->take(20)
        ->orderBy('name', 'ASC')->get();
        return response()->json(['termini'=>$termini]);
    }
}
