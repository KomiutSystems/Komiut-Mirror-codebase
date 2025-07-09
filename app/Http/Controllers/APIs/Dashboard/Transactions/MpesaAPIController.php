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
        $page = max(intval($request->page ?? 1), 1) - 1;
        $offset = $page * 20;

        $from_date = $request->date ? Carbon::parse($request->date) : Carbon::today();
        $to_date = $from_date->copy()->addDay();

        $vehicles = explode(',', str_replace(['[', ']'], '', $request->vehicles ?? ''));
        $vehicles = array_filter(array_map('trim', $vehicles));

        $search = $request->search ?? '';

        $mpesa = Mpesa::select('mpesas.*')
            ->join('transactions', 'mpesas.id', '=', 'transactions.mpesa_id')
            ->join('vehicles', 'transactions.vehicle_id', '=', 'vehicles.id')
            ->leftJoin('saccos', 'vehicles.sacco_id', '=', 'saccos.id')
            ->whereBetween('mpesas.TransTime', [$from_date, $to_date]);

        if ($request->sacco > 0) {
            $mpesa->where('vehicles.sacco_id', $request->sacco);
        }

        if (count($vehicles) > 0) {
            $mpesa->whereIn('transactions.vehicle_id', $vehicles);
        }

        if ($search !== '') {
            $mpesa->where(function ($q) use ($search) {
                $like = '%' . $search . '%';
                $q->where('mpesas.TransID', 'LIKE', $like)
                    ->orWhere('mpesas.FirstName', 'LIKE', $like)
                    ->orWhere('mpesas.MiddleName', 'LIKE', $like)
                    ->orWhere('mpesas.LastName', 'LIKE', $like)
                    ->orWhere('vehicles.plate', 'LIKE', $like)
                    ->orWhere('saccos.name', 'LIKE', $like);
            });
        }

        if ($request->amount !== "") {
            $mpesa->whereBetween('mpesas.TransAmount', [$request->amount, $request->amount]);
        }

        $mpesaResults = $mpesa->orderBy('mpesas.TransTime', 'DESC')
            ->skip($offset)->take(20)
            ->with(['transaction.vehicle.sacco']) // optional: for frontend
            ->get();

        return response()->json(['mpesa' => $mpesaResults]);
    }
}
