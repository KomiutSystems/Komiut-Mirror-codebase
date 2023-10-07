<?php

namespace App\Http\Controllers\Dashboard\Search;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
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
}
