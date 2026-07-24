<?php

namespace App\Http\Controllers\Dashboard\Queues;

use App\Http\Controllers\Controller;
use App\Models\QueueStatus;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Yajra\DataTables\DataTables;

class QueueStatusController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(['permission:View Queue Statuses']);
    }
    public function index(){
        return view('dashboard.queues.queue_statuses');
    }
    public function getQueueStatuses(Request $request){

        $queueStatus = QueueStatus::orderBy('name', 'ASC');

        return DataTables::of($queueStatus)
            ->filter(function($query) use($request){
                $query->where('name', 'LIKE', '%'.$request->search.'%')
                ->where('status', 'LIKE','%'.$request->status.'%')
                ->where('active', $request->active);
            })->editColumn('created_at', function ($row) {
                return Carbon::parse($row->created_at)->diffForHumans();
            })->addColumn('action', function ($row) {
                $actionBtn = '<div style="white-space: nowrap;" class="text-end">' .
                    '<span class="d-none id">' . $row->id . '</span>' .
                    '<span class="d-none name">' . $row->name . '</span>' .
                    '<span class="d-none status">' . $row->status . '</span>' .
                    '<span class="d-none active">' . $row->active . '</span>';
                    if(auth()->user()->can('Edit Queue Statuses'))
                        $actionBtn .= '<button class="btn-edit btn btn-primary btn-sm" data-toggle="modal" data-target="#routeModal"><i class="fas fa-edit"></i> Edit</button> ';
                    $actionBtn .= '<!--<a href="' . url('/route/stage/remove/' . $row->id) . '" class="delete btn btn-danger btn-sm">Delete</a>-->'
                    . '</div>';
                return $actionBtn;
            })->addIndexColumn()->escapeColumns([])->make();
    }
    public function addQueueStatus(Request $request){
        if(auth()->user()->can('Add Queue Statuses') || auth()->user()->can('Edit Queue Statuses')){
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
    public function searchQueueStatuses(Request $request)
    {
        return json_encode(QueueStatus::where('name', 'LIKE', '%' . $request->q . '%')
            ->skip(0)->take(5)->get());
    }
}
