<?php

namespace App\Http\Controllers;

use App\Models\Gender;
use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class IndexController extends Controller
{
    public function index(Request $request){
        $services = Service::take(6)->skip(0)->get();
        return view('index', @compact('services'));
    }

    public function viewService(Request $request){
        $service = Service::find($request->id);
        if($service==null){
            return redirect()->to('/');
        }
        return view('service', @compact('service'));
    }
    public function getGenders(Request $request){
        return json_encode(Gender::where('name', 'LIKE', '%'.$request->q.'%')
        ->orderBy('name', 'asc')->get());
    }
    public function payOnline(Request $request){
        return view('pay_online');
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
