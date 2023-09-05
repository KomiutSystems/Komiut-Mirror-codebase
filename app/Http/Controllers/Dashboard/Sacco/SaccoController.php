<?php

namespace App\Http\Controllers\Dashboard\Sacco;

use App\Http\Controllers\Controller;
use App\Models\Sacco;
use App\Models\Status;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Yajra\DataTables\DataTables;

class SaccoController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(['permission:View Saccos']);
    }
    public function index()
    {
        return view('dashboard.saccos.saccos');
    }

    public function sacco($id)
    {
        $sacco = Sacco::find($id);
        return view('dashboard.saccos.sacco', compact('sacco'));
    }

    public function getSaccos(Request $request)
    {

        $saccos = Sacco::orderBy('name', 'DESC');

        if ($request->has('search') && !empty($request->search)) {
            $saccos = $saccos->where('name', 'like', '%' . $request->search . '%');
        }

        $saccos = $saccos->when($request->has('status') && $request->status != '', function ($query) use ($request) {
            return $query->where('status', $request->status);
        });
        if(Auth::user()->sacco_id > 0){
            $saccos = $saccos->where('id', Auth::user()->sacco_id);
        }

        return DataTables::of($saccos)
            ->editColumn('created_at', function ($row) {
                return Carbon::parse($row->created_at)->diffForHumans();
            })->addColumn('action', function ($row) {
                $actionBtn = '<div style="white-space: nowrap;" class="text-end">' .
                    '<span class="d-none id">' . $row->id . '</span>' .
                    '<span class="d-none name">' . $row->name . '</span>' .
                    '<span class="d-none slogan">' . $row->slogan . '</span>' .
                    '<span class="d-none phone">' . $row->phone . '</span>' .
                    '<span class="d-none status">' . $row->status . '</span>';
                    if(auth()->user()->can('Edit Saccos'))
                        $actionBtn .= '<button class="btn-edit btn btn-primary btn-sm" data-toggle="modal" data-target="#saccoModal"><i class="fas fa-edit"></i> Edit</button> ';
                    $actionBtn .= '<!--<a href="' . url('/saccos/view/' . $row->id) . '" class="delete btn btn-outline-primary btn-sm"><i class="fas fa-eye"></i> View</a>' . '--></div>';
                return $actionBtn;
            })->addIndexColumn()->escapeColumns([])->make();
    }

    public function create(Request $request)
    {
        if(auth()->user()->can('Add Saccos') || auth()->user()->can('Edit Saccos')){
            $validator = Validator::make($request->all(), [
                'id' => 'required|integer|min:0',
                'name' => 'required|string|unique:saccos,name,' . $request->id,
                'slogan' => 'required|string',
                'phone' => 'required|string',
                'status' => 'required|string'
            ]);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }
            $sacco = new Sacco;
            if ($request->id > 0) {
                $sacco = Sacco::findOrFail($request->id);
            }
            $sacco->name = $request->name;
            $sacco->slogan = $request->slogan;
            $sacco->phone = $request->phone;
            $sacco->status = $request->status;

            if ($sacco->save()) {
                return response()->json(['success' => 'Sacco created successfully!']);
            } else {
                return response()->json(['error' => 'Unable to create sacco'], 401);
            }
        } else {
            return response()->json(['error' => 'You do not have permissions to Add/Edit Saccos'], 401);
        }

    }
    
    public function searchSaccos(Request $request)
    {
        $saccos = Sacco::where('name', 'LIKE', '%' . $request->q . '%');
        if(Auth::user()->sacco_id > 0){
            $saccos = $saccos->where('id', Auth::user()->sacco_id);
        }
        $saccos = $saccos->skip(0)->take(5)->get();
        return json_encode($saccos);
    }
}
