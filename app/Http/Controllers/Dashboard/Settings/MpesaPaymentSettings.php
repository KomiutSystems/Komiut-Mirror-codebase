<?php

namespace App\Http\Controllers\Dashboard\Settings;

use App\Http\Controllers\Controller;
use App\Models\MpesaPaymentSetting;
use App\Models\Sacco;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Yajra\DataTables\DataTables;

class MpesaPaymentSettings extends Controller
{

    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(['permission:View Payment Settings']);
    }
    public function index()
    {
        $sacco = Sacco::find(Auth::user()->sacco_id);
        return view('dashboard.settings.mpesa_payment_settings', @compact('sacco'));
    }

    public function getSettings(Request $request)
    {

        $settings = MpesaPaymentSetting::with('sacco')->where('business_short_code', 'LIKE', '%'.$request->search.'%');
        if ($request->sacco > 0) {
            $settings = $settings->where('sacco_id', $request->sacco);
        }
        if ($request->status != "") {
            $settings = $settings->where('status', $request->status);
        }
        $settings = $settings->where('business_short_code', 'LIKE', '%'.$request->search.'%');
        return DataTables::of($settings)
        ->editColumn('created_at', function ($row) {
                return Carbon::parse($row->created_at)->diffForHumans();
            })
            ->editColumn('consumer_key', function ($row) {
                return Str::limit($row->consumer_key, 12, '...');
            })
            ->editColumn('consumer_secret', function ($row) {
                return Str::limit($row->consumer_secret, 12, '...');
            })
            ->editColumn('payment_mode', function ($row) {
                return $row->payment_mode == "CustomerBuyGoodsOnline"?"TILL/BUY GOODS":"PAYBILL";
            })
            ->editColumn('pass_key', function ($row) {
                return Str::limit($row->pass_key, 12, '...');
            })->addColumn('action', function ($row) {
            $actionBtn = '<div style="white-space: nowrap;" class="text-end">' .
                '<span class="d-none id">' . $row->id . '</span>' .
                '<span class="d-none sacco_id">' . $row->sacco_id . '</span>' .
                '<span class="d-none sacco">' . ($row->sacco != null?$row->sacco->name:"") . '</span>' .
                '<span class="d-none consumer_key">' . $row->consumer_key . '</span>' .
                '<span class="d-none consumer_secret">' . $row->consumer_secret . '</span>' .
                '<span class="d-none pass_key">' . $row->pass_key . '</span>' .
                '<span class="d-none business_short_code">' . $row->business_short_code . '</span>' .
                '<span class="d-none payment_mode">' . $row->payment_mode . '</span>' .
                '<span class="d-none status">' . $row->status . '</span>';
            if (auth()->user()->can('Edit Payment Settings'))
                $actionBtn .= '<button class="btn-edit btn btn-primary btn-sm" data-toggle="modal" data-target="#userModal"><i class="fas fa-edit"></i> Edit</button> ';
            $actionBtn .= '<!--<a href="' . url('/saccos/view/' . $row->id) . '" class="delete btn btn-outline-primary btn-sm"><i class="fas fa-eye"></i> View</a>' . '--></div>';
            return $actionBtn;
        })->addIndexColumn()->escapeColumns([])->make();
    }

    public function addSettings(Request $request)
    {
        if (auth()->user()->can('Add Payment Settings') || auth()->user()->can('Edit Payment Settings')) {
            $validator = Validator::make($request->all(), [
                'id' => 'required|integer|min:0',
                'sacco' => 'required|exists:saccos,id',
                'consumer_key' => 'required|string',
                'consumer_secret' => 'required|string',
                'pass_key' => 'required|string',
                'business_short_code' => 'required|string',
                'payment_mode' => 'required|string',
                'status' => 'required|min:0|max:1|integer'
            ]);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }
            $settings = new MpesaPaymentSetting;
            if ($request->id > 0) {
                $settings = MpesaPaymentSetting::findOrFail($request->id);
            } else {
                $exists = MpesaPaymentSetting::where('sacco_id', $request->sacco)->first();
                if ($exists != null) {
                    $settings = $exists;
                }
            }
            $settings->sacco_id = $request->sacco;
            $settings->consumer_key = $request->consumer_key;
            $settings->consumer_secret = $request->consumer_secret;
            $settings->pass_key = $request->pass_key;
            $settings->business_short_code = $request->business_short_code;
            $settings->payment_mode = $request->payment_mode;
            $settings->status = $request->status;

            if ($settings->save()) {
                return response()->json(['success' => 'Settings updated successfully!']);
            } else {
                return response()->json(['error' => 'Unable to update settings'], 401);
            }
        } else {
            return response()->json(['error' => 'You do not have permissions to Add/Edit Payment Settings'], 401);
        }

    }
}