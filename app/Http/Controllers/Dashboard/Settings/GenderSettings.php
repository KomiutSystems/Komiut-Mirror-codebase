<?php

namespace App\Http\Controllers\Dashboard\Settings;

use App\Http\Controllers\Controller;
use App\Models\Gender;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Yajra\DataTables\DataTables;

class GenderSettings extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(['permission:View Gender Settings']);
    }
    public function index()
    {
        return view('dashboard.settings.gender_settings');
    }
    public function getGenders(Request $request)
    {

        $genders = Gender::orderBy('name', 'DESC');


        return DataTables::of($genders)->filter(function($query) use ($request){
            $query->where('name', 'LIKE', '%'.$request->search.'%');
        })
            ->editColumn('created_at', function ($row) {
                return Carbon::parse($row->created_at)->diffForHumans();
            })->addColumn('action', function ($row) {
                $actionBtn = '<div style="white-space: nowrap;" class="text-end">' .
                    '<span class="d-none id">' . $row->id . '</span>' .
                    '<span class="d-none name">' . $row->name . '</span>' .
                    '<span class="d-none status">' . $row->status . '</span>';
                    if(auth()->user()->can('Edit Gender Settings'))
                        $actionBtn .= '<button class="btn-edit btn btn-primary btn-sm" data-toggle="modal" data-target="#userModal"><i class="fas fa-edit"></i> Edit</button> ';
                    $actionBtn .= '<!--<a href="' . url('/saccos/view/' . $row->id) . '" class="delete btn btn-outline-primary btn-sm"><i class="fas fa-eye"></i> View</a>' . '--></div>';
                return $actionBtn;
            })->addIndexColumn()->escapeColumns([])->make();
    }
    public function addGender(Request $request)
    {
        if(auth()->user()->can('Add Gender Settings') || auth()->user()->can('Edit Gender Settings')){
            $validator = Validator::make($request->all(), [
                'id' => 'required|integer|min:0',
                'name' => 'required|string|unique:genders,name,' . $request->id,
                'status' => 'required|integer|min:0|max:1'
            ]);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }
            $gender = new Gender;
            if ($request->id > 0) {
                $gender = Gender::findOrFail($request->id);
            }
            $gender->name = $request->name;
            $gender->status = $request->status;

            if ($gender->save()) {
                return response()->json(['success' => 'Gender updated successfully!']);
            } else {
                return response()->json(['error' => 'Unable to update gender'], 401);
            }
        } else {
            return response()->json(['error' => 'You do not have permissions to Add/Edit Saccos'], 401);
        }

    }
}
