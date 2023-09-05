<?php

namespace App\Http\Controllers\APIs\Dashboard\Queues;

use App\Http\Controllers\Controller;
use App\Models\QueueStatus;
use Illuminate\Http\Request;

class QueueStatusAPIController extends Controller
{
    public function __construct(){
        $this->middleware('auth:api');
    } 
    public function getQueueStatuses(Request $request){
        
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;

        $queue_statuses = QueueStatus::where('name', 'LIKE', '%'.$request->search.'%')
        ->orderBy('name', 'ASC')->skip($offset)->take(20)->get();
        return response()->json(['queue_statuses'=>$queue_statuses]);
    }
}
