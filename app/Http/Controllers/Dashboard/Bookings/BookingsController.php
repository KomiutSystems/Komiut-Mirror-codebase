<?php

namespace App\Http\Controllers\Dashboard\Bookings;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Parcel;
use App\Models\Sacco;
use App\Models\VehicleUser;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Yajra\DataTables\DataTables;

class BookingsController extends Controller
{
    public function __construct(){
        $this->middleware('auth');
        $this->middleware(['permission:View Passengers|View Parcels']);
    }
    public function index(){
        $sacco = Sacco::find(Auth::user()->sacco_id);
        return view('dashboard.bookings.passengers', @compact('sacco'));
    }
    public function getPassengerBookings(Request $request){

        $bookings = Booking::with(['from', 'to', 'creator', 'queue.vehicle.sacco'])
        ->whereBetween('created_at', [Carbon::parse($request->from_date), Carbon::parse($request->to_date)]);
        if($request->sacco > 0){
            $bookings = $bookings->whereHas('queue.vehicle', function($query) use ($request){
                $query->where('sacco_id', $request->sacco);
            });
        }

        $vehicles = VehicleUser::where('user_id', auth()->user()->id)
        ->where('status', true)->pluck('vehicle_id');
        if(count($vehicles)>0){
            $bookings = $bookings->whereHas('queue', function($query) use($vehicles){
                $query->whereIn('vehicle_id', $vehicles);
            });
        }
        if($request->from > 0){
            $bookings = $bookings->where('from_id', $request->from);
        }
        if($request->to > 0){
            $bookings = $bookings->where('to_id', $request->to);
        }
        $bookings = $bookings->orderBy('created_at', 'DESC');

        return DataTables::of($bookings)
            ->filter(function($query) use($request){
                $query->where(function($q) use($request){
                    $q->where('name', 'LIKE', '%'.$request->search.'%')
                    ->orWhere('phone', 'LIKE', '%'.$request->search.'%')
                    ->orWhereHas('queue.vehicle', function($qu) use($request){
                        $qu->where('plate', 'LIKE', '%'.$request->search.'%');
                    });
                });
            })->editColumn('created_at', function ($row) {
                return Carbon::parse($row->created_at)->diffForHumans();
            })->editColumn('amount', function ($row) {
                return number_format($row->amount, 2, '.', ',');
            })->addColumn('action', function ($row) {
                $actionBtn = '<div style="white-space: nowrap;" class="text-end">' .
                    '<!--<span class="d-none id">' . $row->id . '</span>' .
                    '<span class="d-none queue_number">' . $row->queue_number . '</span>' .
                    '<span class="d-none status">' . $row->status . '</span>' .
                    '<span class="d-none active">' . $row->active . '</span>';
                    if(auth()->user()->can('Edit Passengers'))
                        $actionBtn .= '<button class="btn-edit btn btn-primary btn-sm" data-toggle="modal" data-target="#routeModal"><i class="fas fa-edit"></i> Edit</button> ';
                    $actionBtn .= '--><a href="' . url('/queues/view/' . $row->queue_id) . '" class="btn btn-outline-primary btn-sm"><i class="fas fa-eye"></i> View</a>'
                    . '</div>';
                return $actionBtn;
            })->addIndexColumn()->escapeColumns([])->make();
    }

    public function parcels(){
        $sacco = Sacco::find(Auth::user()->sacco_id);
        return view('dashboard.bookings.parcels', @compact('sacco'));
    }


    public function getParcels(Request $request){
        $parcels = Parcel::with(['from', 'to', 'creator', 'vehicle.sacco'])
        ->whereBetween('created_at', [Carbon::parse($request->from_date), Carbon::parse($request->to_date)]);

        if($request->vehicle > 0){
            $parcels = $parcels->where('vehicle_id', $request->vehicle);
        }
        if($request->sacco > 0){
            $parcels = $parcels->whereHas('vehicle', function($query) use ($request){
                $query->where('sacco_id', $request->sacco);
            });
        }
        if($request->from > 0){
            $parcels = $parcels->where('from_id', $request->from);
        }
        if($request->to > 0){
            $parcels = $parcels->where('to_id', $request->to);
        }

        $vehicles = VehicleUser::where('user_id', auth()->user()->id)
        ->where('status', true)->pluck('vehicle_id');
        if(count($vehicles)>0){
            $parcels = $parcels->whereHas('queue', function($query) use($vehicles){
                $query->whereIn('vehicle_id', $vehicles);
            });
        }
        $parcels = $parcels->orderBy('created_at', 'DESC');
        return DataTables::of($parcels)
            ->filter(function($query) use($request){
                $query->where(function($q) use($request){
                    $q->where('recipient_name', 'LIKE', '%'.$request->search.'%')
                    ->orWhere('recipient_phone', 'LIKE', '%'.$request->search.'%')
                    ->orWhere('recipient_idno', 'LIKE', '%'.$request->search.'%')
                    ->orWhere('sender_name', 'LIKE', '%'.$request->search.'%')
                    ->orWhere('sender_phone', 'LIKE', '%'.$request->search.'%')
                    ->orWhere('sender_idno', 'LIKE', '%'.$request->search.'%')
                    ->orWhere('name', 'LIKE', '%'.$request->search.'%');
                });
            })->editColumn('created_at', function ($row) {
                return Carbon::parse($row->created_at)->diffForHumans();
            })->editColumn('amount', function ($row) {
                return number_format($row->amount, 2, '.', ',');
            })->addColumn('action', function ($row) {
                $actionBtn = '<div style="white-space: nowrap;" class="text-end">' .
                    '<!--<span class="d-none id">' . $row->id . '</span>' .
                    '<span class="d-none queue_number">' . $row->queue_number . '</span>' .
                    '<span class="d-none status">' . $row->status . '</span>' .
                    '<span class="d-none active">' . $row->active . '</span>';
                    if(auth()->user()->can('Edit Parcels'))
                        $actionBtn .= '<button class="btn-edit btn btn-primary btn-sm" data-toggle="modal" data-target="#routeModal"><i class="fas fa-edit"></i> Edit</button> ';
                    $actionBtn .='--><a href="' . url('/queues/view/' . $row->queue_id) . '" class="btn btn-outline-primary btn-sm"><i class="fas fa-eye"></i> View</a>'
                    . '</div>';
                return $actionBtn;
            })->addIndexColumn()->escapeColumns([])->make();
    }

    public function addParcel(Request $request){
        if(auth()->user()->can('Add Parcels') || auth()->user()->can('Edit Parcels')){
            $validator = Validator::make($request->all(), [
                'id'=>'required|integer|min:0',
                'name'=>'required|string',
                'sender_name'=>'required|string',
                'sender_phone'=>'required|digits:10',
                'sender_idno'=>'required|digits:8',
                'recipient_name'=>'required|string',
                'recipient_phone'=>'required|digits:10|different:sender_phone',
                'recipient_idno'=>'nullable|digits:8',
                'vehicle' => 'nullable|integer|exists:vehicles,id',
                'from' => 'required|integer|exists:places,id',
                'to' => 'required|integer|exists:places,id',
                'amount' => 'required|integer|min:1',
                'status'=>'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }
            //check seat availability
            $parcel = new Parcel;
            if($request->id > 0){
                $parcel = Parcel::findOrFail($request->id);
            }
            $parcel->name = $request->name;
            $parcel->sender_name = $request->sender_name;
            $parcel->sender_phone = $request->sender_phone;
            $parcel->sender_idno = $request->sender_idno;
            $parcel->recipient_name = $request->recipient_name;
            $parcel->recipient_phone = $request->recipient_phone;
            $parcel->recipient_idno = $request->recipient_idno;
            $parcel->from_id = $request->from;
            $parcel->to_id = $request->to;
            $parcel->vehicle_id = $request->vehicle;
            $parcel->amount = $request->amount;
            $parcel->created_by = Auth::user()->id;
            $parcel->status = $request->status;
            if($parcel->save()){
                return response()->json(['success'=>"Parcel updated successfully!"]);
            }else{
                return response()->json(['error'=>'Unable to update parcel'], 401);
            }
        }else{
            return response()->json(['error'=>'You do not have permissions Add/Edit Parcels'], 401);
        }
    }
}
