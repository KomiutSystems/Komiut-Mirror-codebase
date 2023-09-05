<?php

namespace App\Http\Controllers\Dashboard\Sacco;

use App\Http\Controllers\Controller;
use App\Models\Sacco;
use App\Models\SaccoRoute;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Yajra\DataTables\DataTables;

class SaccoRouteController extends Controller
{
    public function __construct(){
        $this->middleware('auth');
        $this->middleware(['permission:View Sacco Routes']);
    }
    public function index(){
        $sacco = Sacco::find(Auth::user()->sacco_id);
        return view('dashboard.saccos.routes', @compact('sacco'));
    }
    public function getRoutes(Request $request)
    {
        return DataTables::of(SaccoRoute::with(['route.from', 'route.to','sacco']))
        ->filter(function($query) use ($request){
            $query->where('status', $request->status);
            if($request->sacco > 0){
                $query->where('sacco_id', $request->sacco);
            }
            if($request->from > 0){
                $query->whereHas('route', function($q) use($request){
                    $q->where('from_id', $request->from);
                });
            }
            if($request->to > 0){
                $query->whereHas('route', function($q) use($request){
                    $q->where('to_id', $request->to);
                });
            }
        })->editColumn('created_at', function ($row) {
            return Carbon::parse($row->created_at)->diffForHumans();
        })->addColumn('action', function ($row) {
            $actionBtn = '<div style="white-space: nowrap;" class="text-end">' .
                '<span class="d-none id">' . $row->id . '</span>' .
                '<span class="d-none amount">' . $row->amount . '</span>' .
                '<span class="d-none min_amount">' . $row->min_amount . '</span>' .
                '<span class="d-none sacco">' . $row->sacco->name . '</span>' .
                '<span class="d-none sacco_id">' . $row->sacco_id . '</span>' .
                '<span class="d-none route_id">' . $row->route_id . '</span>' .
                '<span class="d-none route">' . $row->route->from->name.' - '.$row->route->to->name.' ('.$row->route->name.')' . '</span>' .
                '<span class="d-none status">' . $row->status . '</span>';
                if(auth()->user()->can('Edit Sacco Routes'))
                    $actionBtn .= '<button class="btn-edit btn btn-primary btn-sm" data-toggle="modal" data-target="#saccoModal"><i class="fas fa-edit"></i> Edit</button> ';
                $actionBtn .= '<!--<a href="' . url('/saccos/view/' . $row->id) . '" class="delete btn btn-outline-primary btn-sm"><i class="fas fa-eye"></i> View</a>' . '--></div>';
            return $actionBtn;
        })->addIndexColumn()->escapeColumns([])->make();
    }
    
    public function addSaccoRoute(Request $request)
    {
        if(auth()->user()->can('Edit Sacco Routes') || auth()->user()->can('Edit Sacco Routes') ){
            $validator = Validator::make($request->all(), [
                'id' => 'required|integer|min:0',
                'sacco' => 'required|exists:saccos,id',
                'route' => 'required|exists:routes,id',
                'amount' => 'required|min:1|numeric',
                'min_amount' => 'required|min:1|numeric',
                'status' => 'required|integer|min:0|max:1'
            ]);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }
            
            if(SaccoRoute::where('route_id', $request->route)->where('sacco_id', $request->sacco)
            ->where('id', '<>', $request->id)->count() > 0){
                return response()->json(['error' => 'Route already exists'], 401);
            }
            $saccoRoute = new SaccoRoute;
            if ($request->id > 0) {
                $saccoRoute = SaccoRoute::findOrFail($request->id);
            }
            $saccoRoute->sacco_id = $request->sacco;
            $saccoRoute->route_id = $request->route;
            $saccoRoute->amount = $request->amount;
            $saccoRoute->min_amount = $request->min_amount;
            $saccoRoute->status = $request->status;
            $saccoRoute->user_id = Auth::user()->id;

            if ($saccoRoute->save()) {
                return response()->json(['success' => 'Sacco route updated successfully!']);
            } else {
                return response()->json(['error' => 'Unable to update sacco route'], 401);
            }
        }else{
            return response()->json(['error' => 'Permissions to Add/Edit Sacco Routes Denied'], 401);
        }
    }
/*
    public function removeSaccoRoute($id): RedirectResponse
    {

        $saccoRoute = SaccoRoute::find($id)->delete();

        if ($saccoRoute) {
            return redirect()->back()->with('success', 'Sacco route removed successfully');
        } else {
            return redirect()->back()->with('error', 'Failed to remove sacco route');
        }

    }

    public function getSaccoRoutes($id): JsonResponse
    {

        $saccoRoutes = SaccoRoute::with([
            'route_id.from_id',
            'route_id.to_id',
            'user_id',
            'sacco_id' => function ($query) use ($id) {
                $query->where('id','=', $id);
            },
        ]);

        return DataTables::of($saccoRoutes)
            ->addColumn('action', function ($saccoRoutes) use ($id) {
                $div = "<div class='text-right'>".
                    "<a href='" . url('sacco/route/remove/'.$saccoRoutes->id) . "' class='btn btn-danger btn-sm'>Remove</a>".
                    "</div>";
                return $div;
            })->addIndexColumn()->escapeColumns([])->make();
    }

    public function getSaccoMembers(Request $request)
    {
        return Datatables::of(User::select("users.id", "saccos.name as sacco","users.email", "users.firstname", "users.phone", "users.lastname", "users.email", "roles.name as role", "users.updated_at", "users.status")
            ->join("saccos", "users.sacco_id", "=", "saccos.id")->leftJoin("roles", "roles.code", "=", "users.role")
        )->filter(function($query) use($request){
            $query->where("users.sacco_id", $request->id)->where(function($q) use($request){
                $q->where("users.email",'LIKE', '%'.$request->search.'%')->orWhere("users.firstname",'LIKE', '%'.$request->search.'%')
                    ->orWhere("users.lastname",'LIKE', '%'.$request->search.'%')->orWhere("users.phone",'LIKE', '%'.$request->search.'%');
            });
        })->addColumn('name', function ($query) {
            return "<div class='user-card'>
                        <div class='user-avatar bg-info'>
                            <span>".substr($query->firstname,0,1).substr($query->lastname,0,1)."</span>
                        </div>
                        <div class='user-info'>
                            <strong class='text-primary small'>".$query->firstname." ".$query->lastname."</strong><br>
                            <span>".$query->email."</span>
                        </div>
                    </div>";
        })->addColumn('status', function ($query) {
            return $query->status == 1 ? "<span class='badge badge-dot badge-success'>Active</span>" : "<span class='badge badge-dot badge-danger'>In-Active</span>";
        })->addColumn('joined_at', function ($query) {
            return \Carbon\Carbon::parse($query->joined_at)->setTimezone('Africa/Nairobi')->format('d M, Y');
        })->addColumn('action', function ($query) use ($request) {
            $div = "<div class='text-right'>".
                "<a href='" . url('admin/sacco/member/revoke/'.$query->id) . "' class='btn btn-danger btn-sm'>Remove</a>".
                "</div>";
            return $div;
        })->addColumn('created_at', function ($query) {
            return \Carbon\Carbon::parse($query->updated_at)->setTimezone('Africa/Nairobi')->format('d M, Y h:i A').'MAYA';
        })->escapeColumns([])->make();
    }*/

}
