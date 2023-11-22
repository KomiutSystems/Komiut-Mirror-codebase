<?php

namespace App\Http\Controllers\Dashboard\Settings;

use App\Http\Controllers\Controller;
use App\Models\PointSetting;
use App\Models\Sacco;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Yajra\DataTables\DataTables;

class PointsSettingsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(['permission:View Payment Settings']);
    }
    public function index()
    {
        $sacco = Sacco::find(Auth::user()->sacco_id);
        return view('dashboard.settings.points_settings', @compact('sacco'));
    }

    public function getPointSettings(Request $request)
    {
        /*$dates = explode('to', $request->date);
        $start_date = Carbon::parse($dates[0]);
        $end_date = "";
        if(count($dates) > 1){
            $end_date = Carbon::parse($dates[1])->addDay();
        }else{
            $end_date = $start_date->copy()->addDay();
        }*/
        $settings = PointSetting::with(['sacco', 'role'])
        /*->whereBetween("created_at", [$start_date, $end_date])*/;
        if ($request->sacco > 0) {
            $settings = $settings->where('sacco_id', $request->sacco);
        }
        if ($request->role > 0) {
            $settings = $settings->where('role_id', $request->role);
        }

        if (strlen($request->points_on) > 0) {
            $settings = $settings->where('points_on', $request->points_on);
        }

        if (strlen($request->points_by) > 0) {
            $settings = $settings->where('points_type', $request->points_by);
        }
        $settings = $settings->where('status', $request->status);

        //$settings = $settings->where('business_short_code', 'LIKE', '%'.$request->search.'%');
        return DataTables::of($settings)
            ->editColumn('created_at', function ($row) {
                return Carbon::parse($row->created_at)->diffForHumans();
            })
            ->editColumn('start_date', function ($row) {
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
                '<span class="d-none start_date">' . Carbon::parse($row->start_date)->format('Y-m-d') . '</span>' .
                '<span class="d-none status">' . $row->status . '</span>';
            if (auth()->user()->can('Edit Payment Settings'))
                $actionBtn .= '<button class="btn-edit btn btn-primary btn-sm" data-toggle="modal" data-target="#userModal"><i class="fas fa-edit"></i> Edit</button> ';
            $actionBtn .= '<!--<a href="' . url('/saccos/view/' . $row->id) . '" class="delete btn btn-outline-primary btn-sm"><i class="fas fa-eye"></i> View</a>' . '--></div>';
            return $actionBtn;
        })->addIndexColumn()->escapeColumns([])->make();
    }

    public function addPointsSettings(Request $request)
    {
        if (auth()->user()->can('Add Point Settings') || auth()->user()->can('Edit Point Settings')) {
            $validator = Validator::make($request->all(), [
                'id' => 'required|integer|min:0',
                'value' => 'required|numeric|min:0',
                'points_by' => 'required|string',
                'points_on' => 'required|string',
                'sacco' => 'nullable|integer',
                'role' => 'nullable|integer',
                'start_date'=>'required|date',
                'status' => 'required|min:0|max:1|integer'
            ]);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }
            $settings = new PointSetting;
            if ($request->id > 0) {
                $settings = PointSetting::findOrFail($request->id);
            } else {
                $exists = PointSetting::where('sacco_id', $request->sacco)->where('points_type', $request->points_by)->
                    where('points_on', $request->points_on)->where('role_id', $request->role)->first();
                if ($exists != null) {
                    $settings = $exists;
                }
            }
            $settings->amount = $request->points_by == 'by amount' ? $request->value : null;
            $settings->items = $request->points_by == 'by items' ? $request->value : null;
            $settings->points_on = $request->points_on;
            $settings->points_type = $request->points_by;
            $settings->role_id = $request->role;
            $settings->sacco_id = $request->sacco;
            $settings->status = $request->status;
            $settings->start_date = Carbon::parse($request->start_date);

            if ($settings->save()) {
                return response()->json(['success' => 'Settings updated successfully!']);
            } else {
                return response()->json(['error' => 'Unable to update settings'], 401);
            }
        } else {
            return response()->json(['error' => 'You do not have permissions to Add/Edit Point Settings'], 401);
        }
    }
}
