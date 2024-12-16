<?php

namespace App\Http\Controllers;

use App\Models\Gender;
use App\Models\Place;
use App\Models\Queue;
use App\Models\SeatArrangement;
use App\Models\Service;
use App\Models\Terminus;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Yajra\DataTables\DataTables;

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
        $vehicle = Vehicle::with('sacco', 'seat.seat_arrangements')->where('till_number', $request->till_number)->first();
        $seat = null;
        if($request->seat > 0){
            $seat = SeatArrangement::find($request->seat);
        }
        if($vehicle == null){
            return redirect()->to('/');
        }
        return view('pay_online', @compact('vehicle', 'seat'));
    }
    public function checkLogin()
    {
        if (Auth::check()) {
            return response()->json(['loggedIn' => true]);
        } else {
            return response()->json(['loggedIn' => false]);
        }
    }

    public function termini(){
        return view('termini');
    }
    public function getTermini(Request $request)
    {

        $termini = Terminus::with('place')->orderBy('name', 'ASC');

        return DataTables::of($termini)
            ->filter(function ($query) use ($request) {
                $query->where('name', 'LIKE', '%' . $request->search . '%')
                    ->where('status', 1);
                    if($request->search_place > 0){
                        $query->where('place_id', $request->search_place);
                    }
            })->editColumn('created_at', function ($row) {
            return Carbon::parse($row->created_at)->diffForHumans();
        })->addColumn('action', function ($row) {
            $actionBtn = '<div style="white-space: nowrap;" class="text-end">' .
                '<a href="' . url('termini/view/' . $row->id) . '" class="btn btn-primary btn-sm"><i class="fas fa-eye"></i> View</a>'
                . '</div>';
            return $actionBtn;
        })->addIndexColumn()->escapeColumns([])->make();
    }
    public function viewTerminus(Request $request){
        $terminus = Terminus::with('place')->find($request->id);
        if($terminus == null){
            return redirect()->to('/');
        }
        return view('terminus', compact('terminus'));
    }
    public function getTerminusQueues(Request $request)
    {

        $queues = Queue::with('route.from', 'route.to', 'vehicle.sacco')->where('terminus_id', $request->id)
        ->whereHas('queue_status', function($query){
            $query->whereIn('status', ['Pending', 'Active']);
        })->orderBy('created_at', 'ASC');

        return DataTables::of($queues)
            ->filter(function ($query) use ($request) {
                $query->whereHas('vehicle', function($query) use($request){
                    $query->where('plate', 'LIKE', '%'.$request->search.'%');
                });
                if($request->from > 0){
                    $query->whereHas('route', function($query) use($request){
                        $query->where('from_id',$request->from);
                    });
                }
                if($request->to > 0){
                    $query->whereHas('route', function($query) use($request){
                        $query->where('to_id',$request->to);
                    });
                }
            })->editColumn('created_at', function ($row) {
            return Carbon::parse($row->created_at)->diffForHumans();
        })->addColumn('action', function ($row) {
            $actionBtn = '<div style="white-space: nowrap;" class="text-end">' .
                '<a href="' . url('pay_online?till_number=' . $row->vehicle->till_number) . '" class="btn btn-primary btn-sm"><i class="fas fa-wallet"></i> Pay</a>'
                . '</div>';
            return $actionBtn;
        })->addIndexColumn()->escapeColumns([])->make();
    }
    public function searchPlaces(Request $request)
    {
        return json_encode(Place::where('name', 'LIKE', '%' . $request->q . '%')
            ->skip(0)->take(5)->get());
    }
}
