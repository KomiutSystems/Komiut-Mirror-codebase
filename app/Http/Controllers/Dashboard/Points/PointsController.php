<?php

namespace App\Http\Controllers\Dashboard\Points;

use App\Http\Controllers\Controller;
use App\Models\MpesaBookingCallback;
use App\Models\MpesaQrcodePayment;
use App\Models\Point;
use App\Models\PointSetting;
use App\Models\PointTransaction;
use App\Models\Sacco;
use App\Models\Transaction;
use App\Models\User;
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



        $transactions = PointTransaction::with('mpesa_qrcode_payment.qrcode_payment.user', 'mpesa_booking_callback.booking.user');
        if ($request->date != "") {
            $dates = explode('to', $request->date);
            $start_date = Carbon::parse($dates[0]);
            $end_date = "";
            if (count($dates) > 1) {
                $end_date = Carbon::parse($dates[1])->addDay();
            } else {
                $end_date = $start_date->copy()->addDay();
            }

            $transactions = $transactions->whereBetween('trans_date', [$start_date, $end_date]);
        }
        if ($request->sacco > 0) {
            $transactions = $transactions->where(function ($query) use ($request) {
                $query->whereHas('mpesa_qrcode_payment', function ($query) use ($request) {
                    $query->whereHas('qrcode_payment.vehicle', function ($query) use ($request) {
                        $query->where('sacco_id', $request->sacco);
                    });
                })->orWhereHas('mpesa_booking_callback', function ($query) use ($request) {
                    $query->whereHas('booking.queue.vehicle', function ($query) use ($request) {
                        $query->where('sacco_id', $request->sacco);
                    });
                });
            });
        }
        if (!auth()->user()->can('View Points')) {
            $transactions = $transactions->where(function ($query) {
                $query->whereHas('mpesa_qrcode_payment.qrcode_payment', function ($query) {
                    $query->where('user_id', auth()->user()->id);
                })->orWhereHas('mpesa_booking_callback.booking', function ($query) {
                    $query->where('user_id', auth()->user()->id);
                });
            });
        }

        return DataTables::of($transactions->orderBy('points', 'DESC')->skip(0)->take(5000)->get())
            ->addColumn('name', function ($row) {
                return $row->mpesa_booking_callback != null ? $row->mpesa_booking_callback->booking->user->firstname : $row->mpesa_qrcode_payment->qrcode_payment->user->firstname;
            })->addColumn('phone', function ($row) {
                return $row->mpesa_booking_callback != null ? $row->mpesa_booking_callback->phone : $row->mpesa_qrcode_payment->phone;
            })->addIndexColumn()->escapeColumns([])->make();
    }
}
