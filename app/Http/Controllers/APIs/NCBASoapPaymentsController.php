<?php

namespace App\Http\Controllers\APIs;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class NCBASoapPaymentsController extends Controller
{
    public function mpesaPayments(Request $request){
        $server = new \SoapServer(null, array('uri' => url('/mpesa/confirmation')));
        $server->setClass(server::class);
        $server->handle();
    }
}
