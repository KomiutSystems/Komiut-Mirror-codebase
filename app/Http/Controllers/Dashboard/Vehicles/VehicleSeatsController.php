<?php

namespace App\Http\Controllers\Dashboard\Vehicles;

use App\Http\Controllers\Controller;
use App\Models\Seat;
use App\Models\SeatArrangement;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Yajra\DataTables\DataTables;

class VehicleSeatsController extends Controller
{
    public function __construct(){
        $this->middleware('auth');
        $this->middleware(['permission:View Seat Settings']);
    }
    public function index(){
        return view('dashboard.vehicles.seat_settings');
    }
    
    public function addSeat(Request $request)
    {
        if(auth()->user()->can('Edit Seat Settings') || auth()->user()->can('Add Seat Settings')){
            $validator = Validator::make($request->all(), [
                'id' => 'required|min:0|integer',
                'name' => 'required|string|unique:seats,name,' . $request->id,
                'seats' => 'required|min:1|integer',
                'rows' => 'required|min:1|integer',
                'columns' => 'required|min:1|integer',
                'status' => 'required|min:0|integer',
            ]);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }
            $seat = new Seat;
            if ($request->id > 0) {
                $seat = Seat::findOrFail($request->id);
            }
            $seat->name = $request->name;
            $seat->seats = $request->seats;
            $seat->rows = $request->rows;
            $seat->columns = $request->columns;
            $seat->status = $request->status;
            if ($seat->save()) {
                return response()->json(['success' => 'Seat saved successfully']);
            } else {
                return response()->json(['error' => 'Unable to update seat'], 401);
            }
        }else{
            return response()->json(['error' => 'Permissions to Add/Edit Seat Denied'], 401);
        }
    }

    public function getSeatSettings(Request $request){
        return DataTables::of(Seat::orderBy('name', 'ASC'))
        ->filter(function($query) use ($request){
            $query->where('name', 'LIKE', '%'.$request->search.'%')
            ->where('status', $request->status);
        })->editColumn('created_at', function ($row) {
            return Carbon::parse($row->created_at)->diffForHumans();
        })->addColumn('action', function ($row) {
            $actionBtn = '<div style="white-space: nowrap;" class="text-end">' .
                '<span class="d-none id">' . $row->id . '</span>' .
                '<span class="d-none name">' . $row->name . '</span>' .
                '<span class="d-none rows">' . $row->rows . '</span>' .
                '<span class="d-none seats">' . $row->seats . '</span>' .
                '<span class="d-none columns">' . $row->columns.'</span>' .
                '<span class="d-none status">' . $row->status . '</span>';
                if(auth()->user()->can('Edit Seat Settings'))
                    $actionBtn .= '<button class="btn-edit btn btn-primary btn-sm" data-toggle="modal" data-target="#vehicleModal"><i class="fas fa-edit"></i> Edit</button> ';
                $actionBtn .= '<a href="' . url('/vehicles/seats/settings/view/' . $row->id) . '" class="btn btn-outline-primary btn-sm"><i class="fas fa-eye"></i> View</a></div>';
            return $actionBtn;
        })->addIndexColumn()->escapeColumns([])->make();
    }
    
    public function viewSeatSetting(Request $request){
        $seat =  Seat::with(['seat_arrangements'=>function($query){
            $query->orderBy('row', 'ASC')->orderBy('column', 'ASC');
        }])->where('id', $request->id)->first();
        if($seat == null){
            return redirect()->to('vehicles/seats/settings');
        }
        return view('dashboard.vehicles.seat_arrangement', @compact('seat'));
    }

    public function addSeatArrangement(Request $request){
        if(auth()->user()->can('Edit Seat Settings') || auth()->user()->can('Add Seat Settings')){
            $validator = Validator::make($request->all(), [
                "id"=>"integer|required|min:0",
                "seat_id"=>"required|exists:seats,id",
                "row"=>"integer|required|min:1",
                "column"=>"integer|required|min:1",
                "name"=>"string|required",
            ]);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }

            if(SeatArrangement::where('seat_id', $request->seat_id)->where('row', $request->row)->where('column', $request->column)
            ->where('id', '<>', $request->id)->count() > 0 || SeatArrangement::where('seat_id', $request->seat_id)->where('name', $request->name)
            ->where('id', '<>', $request->id)->count() > 0){
                return response()->json(['error' => 'Seat Arrangement already exists'], 400);
            }
            $seatArrangement = new SeatArrangement;
            if($request->id > 0){
                $seatArrangement = SeatArrangement::findOrFail($request->id);
            }else{
                $seat = Seat::where('id', $request->seat_id)->first();
                if($seat->seats <= SeatArrangement::where('seat_id', $request->seat_id)->count()){
                    return response()->json(['error' => 'Seats already exhausted'], 400);
                }
            }
            $seatArrangement->name = $request->name;
            $seatArrangement->seat_id = $request->seat_id;
            $seatArrangement->row = $request->row;
            $seatArrangement->column = $request->column;
            $seatArrangement->status = $request->status;
            if($seatArrangement->save()){
                return response()->json(['success' => 'Seat Arrangement updated successfully!']);
            }else{
                return response()->json(['error' => 'Unable to update Seat Arrangement!'], 401);
            }
        }else{
            return response()->json(['error' => 'Permissions to Add/Edit Seat Arrangement Denied'], 401);
        }
    }
    
    public function getSeatArrangements(Request $request){
        return DataTables::of(SeatArrangement::where('seat_id', $request->id)->orderBy('row', 'ASC')->orderBy('column', 'ASC'))
        ->filter(function($query) use ($request){
            $query->where('name', 'LIKE', '%'.$request->search.'%')
            ->where('status', $request->status);
        })->editColumn('created_at', function ($row) {
            return Carbon::parse($row->created_at)->diffForHumans();
        })->addColumn('action', function ($row) {
            $actionBtn = '<div style="white-space: nowrap;" class="text-end">' .
                '<span class="d-none id">' . $row->id . '</span>' .
                '<span class="d-none name">' . $row->name . '</span>' .
                '<span class="d-none row">' . $row->row . '</span>' .
                '<span class="d-none column">' . $row->column.'</span>' .
                '<span class="d-none status">' . $row->status . '</span>';
                if(auth()->user()->can('Edit Seat Settings'))
                    $actionBtn .= '<button class="btn-edit btn btn-primary btn-sm" data-toggle="modal" data-target="#vehicleModal"><i class="fas fa-edit"></i> Edit</button> ';
                $actionBtn .= '<!--<a href="' . url('/vehicles/seats/settings/view/' . $row->id) . '" class="btn btn-outline-primary btn-sm"><i class="fas fa-eye"></i> View</a>--></div>';
            return $actionBtn;
        })->addIndexColumn()->escapeColumns([])->make();
    }
    public function searchSeats(Request $request)
    {
        return json_encode(Seat::select('id', 'name')->where('name', 'LIKE', '%' . $request->q . '%')->skip(0)->take(5)->get());
    }
}
