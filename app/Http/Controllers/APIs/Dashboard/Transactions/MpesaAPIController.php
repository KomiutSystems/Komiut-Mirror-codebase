<?php

namespace App\Http\Controllers\APIs\Dashboard\Transactions;

use App\Http\Controllers\Controller;
use App\Models\Mpesa;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;

class MpesaAPIController extends Controller
{

    public function __construct(){
        $this->middleware('auth:sanctum');
    }
    public function getTransactions(Request $request)
    {

        $page = intval($request->get('page', 1)) - 1;
        $offset = $page * 20;

        $from_date = $request->filled('date') ? Carbon::parse($request->date) : Carbon::today();
        $to_date = $from_date->copy()->addDay();

        $vehicles = collect(explode(',', trim($request->vehicles, '[]')))
            ->map(fn($v) => trim($v))
            ->filter()
            ->toArray();

        $mpesa = Mpesa::with(['transaction.vehicle.sacco'])
            ->whereBetween('TransTime', [$from_date, $to_date]);

        // Filter by Sacco
        if ($request->filled('sacco') && $request->sacco > 0) {
            $mpesa->whereHas('transaction.vehicle', function ($query) use ($request) {
                $query->where('sacco_id', $request->sacco);
            });
        }

        // Filter by Vehicle IDs
        if (!empty($vehicles)) {
            $mpesa->whereHas('transaction', function ($query) use ($vehicles) {
                $query->whereIn('vehicle_id', $vehicles);
            });
        }

        // Full-text like search
        if ($request->filled('search')) {
            $search = $request->search;
            $mpesa->where(function ($query) use ($search) {
                $query->where('TransID', 'LIKE', "%$search%")
                    ->orWhere('FirstName', 'LIKE', "%$search%")
                    ->orWhere('MiddleName', 'LIKE', "%$search%")
                    ->orWhere('LastName', 'LIKE', "%$search%")
                    ->orWhereHas('transaction.vehicle', function ($q) use ($search) {
                        $q->where('plate', 'LIKE', "%$search%");
                    })
                    ->orWhereHas('transaction.vehicle.sacco', function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%$search%");
                    });
            });
        }

        // Exact amount filter
        if ($request->filled('amount') && is_numeric($request->amount)) {
            $amount = floatval($request->amount);
            $mpesa->whereBetween('TransAmount', [$amount, $amount]);
        }


        $mpesa = $mpesa
            ->orderBy('TransTime', 'DESC')
            ->skip($offset)
            ->take(20)
            ->get();

        return response()->json(['mpesa' => $mpesa]);
    }
}
