<?php

namespace App\Http\Controllers\APIs\Dashboard\Transactions;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\Sql\LikeSql;
use Carbon\Carbon;
use Illuminate\Http\Request;

class TransactionsAPIController extends Controller
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

        // Process vehicle list
        $vehicles = explode(',', str_replace(['[', ']'], '', $request->vehicles ?? ''));
        $vehicles = array_filter(array_map('trim', $vehicles));

        $search = $request->search ?? '';
        $amount = $request->amount;

        // Start query joining transactions and related tables
        $transactions = Transaction::select('transactions.*')
            ->leftJoin('mpesas', 'transactions.mpesa_id', '=', 'mpesas.id')
            ->leftJoin('cashes', 'transactions.cash_id', '=', 'cashes.id')
            ->join('vehicles', 'transactions.vehicle_id', '=', 'vehicles.id')
            ->leftJoin('saccos', 'vehicles.sacco_id', '=', 'saccos.id')
            ->whereBetween('transactions.trans_date', [$from_date, $to_date]);

        // Filter sacco
        if ($request->sacco > 0) {
            $transactions->where('vehicles.sacco_id', $request->sacco);
        }

        // Filter vehicles
        if (count($vehicles) > 0) {
            $transactions->whereIn('transactions.vehicle_id', $vehicles);
        }

        // Search across mpesa, cash, vehicle, sacco fields
        if ($search !== '') {
            $like = '%' . $search . '%';
            $transactions->where(function ($q) use ($like) {
                $q->where('mpesas.TransID', LikeSql::op(), $like)
                    ->orWhere('mpesas.FirstName', LikeSql::op(), $like)
                    ->orWhere('mpesas.MiddleName', LikeSql::op(), $like)
                    ->orWhere('mpesas.LastName', LikeSql::op(), $like)
                    ->orWhere('cashes.trans_id', LikeSql::op(), $like)
                    ->orWhere('cashes.firstname', LikeSql::op(), $like)
                    ->orWhere('cashes.lastname', LikeSql::op(), $like)
                    ->orWhere('vehicles.plate', LikeSql::op(), $like)
                    ->orWhere('saccos.name', LikeSql::op(), $like);
            });
        }

        // Filter by amount (exact match)
        if ($amount !== "" && $amount !== null) {
            $transactions->where('transactions.amount', $amount);
        }

        // Calculate sums separately to avoid conflicts
        $mpesaSum = (clone $transactions)->whereNotNull('transactions.mpesa_id')->sum('transactions.amount');
        $cashSum = (clone $transactions)->whereNotNull('transactions.cash_id')->sum('transactions.amount');

        // Get paginated data
        $results = $transactions->orderBy('transactions.trans_date', 'DESC')
            ->skip($offset)
            ->take(20)
            ->with(['mpesa', 'cash', 'vehicle.sacco']) // eager load relationships for frontend if needed
            ->get();

        return response()->json([
            'transactions' => $results,
            'mpesa' => $mpesaSum,
            'cash' => $cashSum,
        ]);
    }
}
