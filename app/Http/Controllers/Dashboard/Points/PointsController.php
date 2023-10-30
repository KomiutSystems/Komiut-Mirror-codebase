<?php

namespace App\Http\Controllers\Dashboard\Points;

use App\Http\Controllers\Controller;
use App\Models\Point;
use App\Models\Sacco;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Yajra\DataTables\DataTables;

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
        $start_date = Carbon::parse($request->date);
        $end_date = $start_date->copy()->addDay();
        $points = Point::with('sacco')->whereBetween('end_date', [$start_date, $end_date])->orderBy('points', 'desc');
        if ($request->sacco > 0) {
            $points = $points->where('sacco_id', $request->sacco);
        }return DataTables::of($points)->filter(function ($query) use ($request) {
            $query->where(function($q) use($request){
                $q->where('name', 'LIKE', '%'.$request->search.'%')
                ->orWhere('phone', 'LIKE', '%'.$request->search.'%');   
            });
        })->editColumn('created_at', function ($row) {
            return Carbon::parse($row->end_date)->diffForHumans();
        })
            /*->editColumn('start_date', function ($row) {
                return Carbon::parse($row->start_date)->format('d M, Y');
            })->addColumn('action', function ($row) {
            $actionBtn = '<div style="white-space: nowrap;" class="text-end">' .
                '<span class="d-none id">' . $row->id . '</span>' .
                '<span class="d-none sacco_id">' . $row->sacco_id . '</span>' .
                '<span class="d-none sacco">' . ($row->sacco != null ? $row->sacco->name : "") . '</span>' .
                '<span class="d-none amount">' . $row->amount . '</span>' .
                '<span class="d-none items">' . $row->items . '</span>' .
                '<span class="d-none points_on">' . $row->points_on . '</span>' .
                '<span class="d-none points_type">' . $row->points_type . '</span>' .
                '<span class="d-none role_id">' . $row->role_id . '</span>' .
                '<span class="d-none role">' . ($row->role_id != null ? $row->role->name : "") . '</span>' .
                '<span class="d-none status">' . $row->status . '</span>';
            if (auth()->user()->can('Edit Payment Settings'))
                $actionBtn .= '<button class="btn-edit btn btn-primary btn-sm" data-toggle="modal" data-target="#userModal"><i class="fas fa-edit"></i> Edit</button> ';
            $actionBtn .= '<!--<a href="' . url('/saccos/view/' . $row->id) . '" class="delete btn btn-outline-primary btn-sm"><i class="fas fa-eye"></i> View</a>' . '--></div>';
            return $actionBtn;
        })*/    ->addIndexColumn()->escapeColumns([])->make();
    }
}
