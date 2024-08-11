<?php

namespace App\Http\Controllers\Dashboard\Points;

use App\Http\Controllers\Controller;
use App\Models\MpesaBookingCallback;
use App\Models\MpesaQrcodePayment;
use App\Models\Point;
use App\Models\PointSetting;
use App\Models\PointTransaction;
use App\Models\RedeemedPoint;
use App\Models\Sacco;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VehicleUser;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Yajra\DataTables\DataTables;
use DB;

class PointsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }


    public function index()
    {
        $sacco = Sacco::find(Auth::user()->sacco_id);
        return view('dashboard.points.points', @compact('sacco'));
    }

    public function getPoints(Request $request)
    {
        $points = Point::with('sacco');
        if ($request->date != "") {
            $dates = explode('to', $request->date);
            $start_date = Carbon::parse($dates[0]);
            $end_date = "";
            if (count($dates) > 1) {
                $end_date = Carbon::parse($dates[1])->addDay();
            } else {
                $end_date = $start_date->copy()->addDay();
            }

            $points = $points->whereBetween('end_date', [$start_date, $end_date]);
        }

        if ($request->sacco > 0) {
            $points = $points->where('sacco_id', $request->sacco);
        }
        if (!auth()->user()->can('View Points')) {
            $points = $points->where('user_id', auth()->user()->id);
        }
        $points = $points->where(function ($q) use ($request) {
            $q->where('name', 'LIKE', '%' . $request->search . '%')
                ->orWhere('phone', 'LIKE', '%' . $request->search . '%');
        });

        return DataTables::of($points->orderBy('points', 'DESC')->skip(0)->take(5000)->get())
            ->addIndexColumn()->escapeColumns([])->make();
    }

    public function redeemed()
    {
        $sacco = Sacco::find(Auth::user()->sacco_id);
        return view('dashboard.points.redeemed_points', @compact('sacco'));
    }

    public function getRedeemedPoints(Request $request)
    {
        $points = RedeemedPoint::with('point.sacco', 'from', 'to', 'vehicle');
        if ($request->date != "") {
            $dates = explode('to', $request->date);
            $start_date = Carbon::parse($dates[0]);
            $end_date = "";
            if (count($dates) > 1) {
                $end_date = Carbon::parse($dates[1])->addDay();
            } else {
                $end_date = $start_date->copy()->addDay();
            }

            $points = $points->whereBetween('created_at', [$start_date, $end_date]);
        }

        if ($request->sacco > 0) {
            $points = $points->whereHas('point', function ($query) use ($request) {
                $query->where('sacco_id', $request->sacco);
            });
        }
        if (!auth()->user()->can('View Points')) {
            $points = $points->where('point', function ($query) {
                $query->where('user_id', auth()->user()->id);
            });
        } else {

            $vehicles = VehicleUser::where('user_id', auth()->user()->id)
                ->where('status', true)->pluck('vehicle_id');
            if (count($vehicles) > 0) {
                $points = $points->whereIn('vehicle_id', $vehicles);
            }
        }
        $points = $points->where(function ($q) use ($request) {
            $q->whereHas('point', function ($query) use ($request) {
                $query->where('name', 'LIKE', '%' . $request->search . '%')
                    ->orWhere('phone', 'LIKE', '%' . $request->search . '%');
            });
        });

        return DataTables::of($points->orderBy('created_at', 'DESC')->get())
            ->editColumn('created_at', function ($row) {
                return Carbon::parse($row->created_at)->diffForHumans();
            })->addIndexColumn()->escapeColumns([])->make();
    }
}
