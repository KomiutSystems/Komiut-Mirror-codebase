<?php

namespace App\Http\Controllers\APIs\Dashboard\Queues;

use App\Http\Controllers\Controller;
use App\Models\QueueStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

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
    
    public function addQueueStatus(Request $request){
        if(auth()->user()->can('Add Queue Statuses') || auth()->user()->can('Add Queue Statuses')){
            $validator = Validator::make($request->all(), [
                'id'=>'required|integer|min:0',
                'name' => 'required|string|unique:queue_statuses,name,'.$request->id,
                'active' => 'required|integer|min:0|max:1',
                'status' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }
            $queueStatus = new QueueStatus();
            if($request->id > 0){
                $queueStatus = QueueStatus::findOrFail($request->id);
            }
            $queueStatus->name = $request->name;
            $queueStatus->active = $request->active;
            $queueStatus->status = $request->status;
            if($queueStatus->save()){
                return response()->json(['success'=>"Queue Status updated successfully!"]);
            }else{
                return response()->json(['error'=>'Unable to update queue status'], 401);
            }
        }else{
            return response()->json(['error'=>'You do not have permission to Add/Edit Queue Statuses']);
        }
    }
}
