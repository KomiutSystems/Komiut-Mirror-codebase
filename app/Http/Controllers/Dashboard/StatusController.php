<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Sacco;
use Illuminate\Http\Request;

class StatusController extends Controller
{

    public function __construct(){
        $this->middleware(['auth']);
        }

        public function index(){
            if(!auth()->user()->status){
                return view('dashboard.status');
            }else{
                $sacco = Sacco::where('id', auth()->user()->sacco_id)->first();
                if($sacco != null){
                    if(!$sacco->status){
                        return view('dashboard.status');
                    }
                }
            }
            return redirect()->to('dashboard/home');
        }
}
