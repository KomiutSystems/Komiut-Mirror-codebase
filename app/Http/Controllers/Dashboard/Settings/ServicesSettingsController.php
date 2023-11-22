<?php

namespace App\Http\Controllers\Dashboard\Settings;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Yajra\DataTables\DataTables;
use Illuminate\Support\Str;

class ServicesSettingsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(['permission:View Services Settings']);
    }
    public function index()
    {
        return view('dashboard.settings.services_settings');
    }

    public function getServices(Request $request)
    {
        return DataTables::of(Service::orderBy('id', 'desc'))
            ->editColumn('created_at', function ($row) {
                return Carbon::parse($row->created_at)->diffForHumans();
            })
            ->editColumn('start_date', function ($row) {
                return Carbon::parse($row->start_date)->format('d M, Y');
            })->editColumn('description', function ($row) {
            return Str::words(strip_tags($row->description), 4, '...');
        })->addColumn('action', function ($row) {
            $actionBtn = '<div style="white-space: nowrap;" class="text-end">' .
                '<span class="d-none id">' . $row->id . '</span>' .
                '<span class="d-none name">' . $row->name . '</span>' .
                '<span class="d-none description">' . $row->description . '</span>' .
                '<span class="d-none status">' . $row->status . '</span>';
            if (auth()->user()->can('Edit Services Settings'))
                $actionBtn .= '<button class="btn-edit btn btn-primary btn-sm" data-toggle="modal" data-target="#userModal"><i class="fas fa-edit"></i> Edit</button> ';
            $actionBtn .= '<a href="' . url('/settings/services/view/' . $row->id) . '" class="btn btn-outline-primary btn-sm"><i class="fas fa-eye"></i> View</a>' . '</div>';
            return $actionBtn;
        })->addIndexColumn()->escapeColumns([])->make();
    }

    public function addService(Request $request)
    {
        if (auth()->user()->can('Add Services Settings') || auth()->user()->can('Edit Services Settings')) {
            $validator = Validator::make($request->all(), [
                'id' => 'required|integer|min:0',
                'name' => 'required|string|unique:services,name,' . $request->id,
                'description' => 'nullable|string',
                'status' => 'required|min:0|max:1|integer'
            ]);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }
            $service = new Service;
            if ($request->id > 0) {
                $service = Service::find($request->id);
            }
            $service->name = $request->name;
            $service->description = $request->description;
            $service->status = $request->status;

            if ($service->save()) {
                return response()->json(['success' => 'Service updated successfully!']);
            } else {
                return response()->json(['error' => 'Unable to update service'], 401);
            }
        } else {
            return response()->json(['error' => 'You do not have permissions to Add/Edit Service'], 401);
        }
    }

    public function viewService(Request $request){
        $service = Service::find($request->id);
        if($service == null){
            return redirect()->to('settings/services');
        }
        return view('dashboard.settings.service_setting', @compact('service'));
    }public function uploadServicePicture(Request $request)
    {
        $folderPath = public_path('images/services/');
        $service = Service::findOrFail($request->id);
        if ($service->image != null) {
            if (file_exists(public_path('/images/services/' . $service->image))) {
                unlink(public_path() . '/images/services/' . $service->image);
            }
        }
        $data = $request->image;
        list($type, $data) = explode(';', $data);
        list(, $data) = explode(',', $data);

        $data = base64_decode($data);
        $image_name = $service->id . '.png';
        $path = $folderPath . $image_name;

        file_put_contents($path, $data);
        $service->image = $image_name;
        $service->save();

        return response()->json(['success' => 'Image Uploaded Successfully']);
    }
}
