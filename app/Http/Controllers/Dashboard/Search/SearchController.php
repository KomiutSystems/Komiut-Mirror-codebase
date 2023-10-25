<?php

namespace App\Http\Controllers\Dashboard\Search;

use App\Http\Controllers\Controller;
use App\Models\Sacco;
use App\Models\Terminus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;

class SearchController extends Controller
{
    public function __construct(){
        $this->middleware('auth');
    }
    
    public function searchRoles(Request $request)
    {
        return json_encode(Role::where('name', 'LIKE', '%' . $request->q . '%')
            ->orderBy('name', 'asc')->get());
    }
    
    public function searchSaccos(Request $request)
    {
        $saccos = Sacco::where('name', 'LIKE', '%' . $request->q . '%');
        if(Auth::user()->sacco_id > 0){
            $saccos = $saccos->where('id', Auth::user()->sacco_id);
        }
        $saccos = $saccos->skip(0)->take(5)->get();
        return json_encode($saccos);
    }
    public function searchTermini(Request $request)
    {
        return json_encode(Terminus::with('place')
            ->where('name', 'LIKE', '%' . $request->q . '%')
            ->skip(0)->take(5)->get());
    }
}
