<?php

namespace App\Http\Controllers\APIs\Dashboard\Transactions;

use App\Http\Controllers\Controller;
use App\Models\Mpesa;
use Carbon\Carbon;
use Illuminate\Http\Request;

class MpesaAPIController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function getTransactions(Request $request)
    {

        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $from_date = $request->date != '' ? Carbon::parse($request->date) : Carbon::today();
        $to_date = $from_date->copy()->addDays(1);
        $vehicles = explode(',', str_replace(']', '', str_replace('[', '', $request->vehicles)));
        $all_vehicles = [];

        foreach ($vehicles as $vehicle) {
            $v = trim($vehicle);
            if ($v != '') {
                array_push($all_vehicles, trim($vehicle));
            }
        }

        $mpesa = Mpesa::with(['transaction.vehicle.sacco'])
            ->whereBetween('TransTime', [$from_date, $to_date]);

        // Mpesa carries no SaccoScope of its own (it is written unauthenticated by
        // webhooks), so a SACCO admin must be confined here or they would read
        // every SACCO's payments. Superadmins are unconstrained; the optional
        // ?sacco filter below lets them narrow to one.
        $user = auth()->user();
        if ($user && ! $user->isSuperAdmin() && $user->currentSaccoId()) {
            $mpesa = $mpesa->whereHas('transaction.vehicle', function ($query) use ($user) {
                $query->where('sacco_id', $user->currentSaccoId());
            });
        }

        if ($request->sacco > 0) {
            $mpesa = $mpesa->whereHas('transaction.vehicle', function ($query) use ($request) {
                $query->where('sacco_id', $request->sacco);
            });
        }
        if (count($all_vehicles) > 0) {
            $mpesa = $mpesa->whereHas('transaction', function ($query) use ($all_vehicles) {
                $query->whereIn('vehicle_id', $all_vehicles);
            });
        }
        $mpesa = $mpesa->where(function ($query) use ($request) {
            $query->where('TransID', 'LIKE', '%'.$request->search.'%')
                ->orWhere('FirstName', 'LIKE', '%'.$request->search.'%')
                ->orWhere('MiddleName', 'LIKE', '%'.$request->search.'%')
                ->orWhere('LastName', 'LIKE', '%'.$request->search.'%');
            $query->orWhereHas('transaction.vehicle', function ($q) use ($request) {
                $q->where('plate', 'LIKE', '%'.$request->search.'%');
            })->orWhereHas('transaction.vehicle.sacco', function ($q) use ($request) {
                $q->where('name', 'LIKE', '%'.$request->search.'%');
            });
        });

        if ($request->amount != '') {
            $mpesa = $mpesa->whereBetween('TransAmount', [$request->amount, $request->amount]);
        }
        $mpesa = $mpesa->orderBy('TransTime', 'DESC')->skip($offset)->take(20)->get();

        return response()->json(['mpesa' => $mpesa]);
    }
}
