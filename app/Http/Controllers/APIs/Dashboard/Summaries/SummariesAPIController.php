<?php

namespace App\Http\Controllers\APIs\Dashboard\Summaries;

use App\Http\Controllers\Controller;
use App\Models\Summary;
use Carbon\Carbon;
use Illuminate\Http\Request;
use DB;

class SummariesAPIController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }
    public function getSummaries(Request $request)
    {
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;

        $from_date = $request->date != "" ? Carbon::parse($request->date) : Carbon::today();
        $to_date = $from_date->copy()->addDay();

        $vehicles = explode(',', str_replace(['[', ']'], '', $request->vehicles));
        $vehicles = array_filter(array_map('trim', $vehicles));

        $query = Summary::select(
            'summaries.vehicle_id',
            DB::raw('SUM(mpesa_amount) as mpesa_amount'),
            DB::raw('SUM(mpesa_txn) as mpesa_txn'),
            DB::raw('SUM(cash_amount) as cash_amount'),
            DB::raw('SUM(cash_txn) as cash_txn'),
            DB::raw('SUM(mpesa_amount + cash_amount) as totals'),
            DB::raw('SUM(mpesa_txn + cash_txn) as total_txn'),
            // expense_fee_amount is the one non-numeric column here: it was added
            // later as a string with default '0' (see the 2024_04_18 migration),
            // while mpesa_amount/cash_amount are double and the _txn pair are
            // integers. MySQL coerces the varchar and sums it happily; PostgreSQL
            // has no sum(character varying) and 500s the whole request.
            //
            // NULLIF covers rows holding an empty string rather than '0', which
            // PostgreSQL will not cast to a number.
            DB::raw("SUM(CAST(NULLIF(expense_fee_amount, '') AS DECIMAL(15,2))) as expense_fee_amount")
        )
            ->join('vehicles', 'summaries.vehicle_id', '=', 'vehicles.id')
            ->leftJoin('saccos', 'vehicles.sacco_id', '=', 'saccos.id')
            ->whereBetween('trans_date', [$from_date, $to_date]);

        if ($request->sacco > 0) {
            $query->where('vehicles.sacco_id', $request->sacco);
        }

        if (count($vehicles) > 0) {
            $query->whereIn('summaries.vehicle_id', $vehicles);
        }

        // Apply search directly using JOINed tables
        if (!empty($request->search)) {
            $searchTerm = '%' . $request->search . '%';
            $query->where(function ($q) use ($searchTerm) {
                $q->where('vehicles.plate', 'LIKE', $searchTerm)
                    ->orWhere('saccos.name', 'LIKE', $searchTerm);
            });
        }

        // Clone before pagination for mpesa/cash totals
        $mpesa = (clone $query)->sum('mpesa_amount');
        $cash = (clone $query)->sum('cash_amount');

        // Paginated & grouped result
        $summaries = $query->groupBy('summaries.vehicle_id')
            ->orderByRaw('SUM(mpesa_amount + cash_amount) DESC')
            ->skip($offset)->take(20)
            ->with(['vehicle.sacco', 'vehicle.seat']) // for response mapping
            ->get();

        return response()->json(['summaries' => $summaries, 'mpesa' => $mpesa, 'cash' => $cash]);
    }
}
