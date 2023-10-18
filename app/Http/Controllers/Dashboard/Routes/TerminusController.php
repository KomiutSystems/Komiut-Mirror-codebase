<?php

namespace App\Http\Controllers\Dashboard\Routes;

use App\Http\Controllers\Controller;
use App\Models\Terminus;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Yajra\DataTables\DataTables;

class TerminusController extends Controller
{ 
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(['permission:View Termini']);
    }
    public function index(){
        return view('dashboard.routes.termini');
    }
    public function getTermini(Request $request){

        $termini = Terminus::with('place')->orderBy('name', 'ASC');

        return DataTables::of($termini)
            ->filter(function($query) use($request){
                $query->where('name', 'LIKE', '%'.$request->search.'%')
                ->where('status', $request->status)->where(function($q) use($request){
                    $q->whereHas('place', function($qu) use($request){
                        $qu->where('name', 'LIKE', '%'.$request->search_place.'%');
                    });
                });
            })->editColumn('created_at', function ($row) {
                return Carbon::parse($row->created_at)->diffForHumans();
            })->addColumn('action', function ($row) {
                $actionBtn = '<div style="white-space: nowrap;" class="text-end">' .
                    '<span class="d-none id">' . $row->id . '</span>' .
                    '<span class="d-none place_id">' . $row->place_id . '</span>' .
                    '<span class="d-none place">' . $row->place->name . '</span>' .
                    '<span class="d-none name">' . $row->name . '</span>' .
                    '<span class="d-none status">' . $row->status . '</span>';
                    if(auth()->user()->can('Edit Termini'))
                        $actionBtn .= '<button class="btn-edit btn btn-primary btn-sm" data-toggle="modal" data-target="#routeModal"><i class="fas fa-edit"></i> Edit</button> ';
                    $actionBtn .= '<!--<a href="' . url('/route/stage/remove/' . $row->id) . '" class="delete btn btn-danger btn-sm">Delete</a>-->'
                    . '</div>';
                return $actionBtn;
            })->addIndexColumn()->escapeColumns([])->make();
    }
    
    public function addTerminus(Request $request){
        if(auth()->user()->can('Edit Termini') || auth()->user()->can('Add Termini')){
            $validator = Validator::make($request->all(), [
                'id'=>'required|integer|min:0',
                'name' => 'required|string',
                'place' => 'required|integer|exists:places,id',
                'status' => 'required|integer|min:0|max:1',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }
            if(Terminus::where('name', $request->name)->where('place_id', $request->place)
            ->where('id', '<>',$request->id)->count() > 0){
                return response()->json(['error'=>'Terminus already exists'], 401);
            }
            $terminus = new Terminus();
            if($request->id > 0){
                $terminus = Terminus::findOrFail($request->id);
            }
            $terminus->name = $request->name;
            $terminus->place_id = $request->place;
            $terminus->status = $request->status;
            if($terminus->save()){
                return response()->json(['success'=>"Terminus updated successfully!"]);
            }else{
                return response()->json(['error'=>'Unable to update terminus'], 401);
            }
        }else{
            return response()->json(['error'=>'You do not have permissions for this action'], 401);
        }
    }
    

    public function searchTermini(Request $request)
    {
        return json_encode(Terminus::with('place')
            ->where('name', 'LIKE', '%' . $request->q . '%')
            ->skip(0)->take(5)->get());
    }
}
