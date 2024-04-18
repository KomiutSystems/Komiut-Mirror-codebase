<?php

namespace App\Http\Controllers\Dashboard\Summaries;

use App\Models\Transaction;
use DB;
use App\Models\Sacco;
use App\Http\Controllers\Controller;
use App\Models\Summary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Yajra\DataTables\DataTables;
use Carbon\Carbon;

class SummaryController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(['permission:View Summaries']);
    }
    public function index()
    {
        $sacco = Sacco::find(Auth::user()->sacco_id);
        return view('dashboard.summaries.summaries', @compact('sacco'));
    }
    public function getSummaries(Request $request)
    {
        $from_date = Carbon::parse($request->from_date);
        $to_date = Carbon::parse($request->to_date);
        $summaries = Summary::select(
            'vehicle_id',
            DB::raw('SUM(mpesa_amount) as mpesa_amount'),
            DB::raw('SUM(mpesa_txn) as mpesa_txn'),
            DB::raw('SUM(cash_amount) as cash_amount'),
            DB::raw('SUM(cash_txn) as cash_txn'),
            DB::raw('SUM(mpesa_amount+cash_amount) as totals'),
            DB::raw('SUM(mpesa_txn+cash_txn) as total_txn'),
            DB::raw('SUM(expense_fee_amount) as expense_fee_amount')
        )
            ->with(['vehicle.sacco'])
            ->whereBetween('trans_date', [$from_date, $to_date])->groupBy('vehicle_id');
        if ($request->sacco > 0) {
            $summaries = $summaries->whereHas('vehicle', function ($query) use ($request) {
                $query->where('sacco_id', $request->sacco);
            });
        }
        $transactions = $summaries->where(function ($q) use ($request) {
            $q->orWhereHas('vehicle', function ($query) use ($request) {
                $query->where('plate', 'LIKE', '%' . $request->search . '%');
            })->orWhereHas('vehicle.sacco', function ($query) use ($request) {
                $query->where('name', 'LIKE', '%' . $request->search . '%');
            });
        })->orderBy(DB::raw('SUM(mpesa_amount+cash_amount)'), 'DESC');

        return DataTables::of($transactions)
            ->addColumn("transdate", function ($row) {
                return Carbon::parse($row->trans_date)->format('d M, Y');
            })
            ->editColumn("mpesa_amount", function ($row) {
                return number_format($row->mpesa_amount, 2, '.', ',');
            })
            ->editColumn("mpesa_txn", function ($row) {
                return number_format($row->mpesa_txn, 0, '.', ',');
            })
            ->editColumn("cash_amount", function ($row) {
                return number_format($row->cash_amount, 2, '.', ',');
            })
            ->editColumn("cash_txn", function ($row) {
                return number_format($row->cash_txn, 0, '.', ',');
            })
            ->editColumn("totals", function ($row) {
                return number_format($row->totals, 2, '.', ',');
            })
            ->editColumn("total_txn", function ($row) {
                return number_format($row->total_txn, 0, '.', ',');
            })
            ->editColumn("expense_fee_amount", function ($row) {
                return number_format($row->expense_fee_amount, 2, '.', ',');
            })->addIndexColumn()->escapeColumns([])->make();
    }
    public function getSummariesCards(Request $request)
    {
        $sacco = $request->sacco > 0 ? $request->sacco : "";
        $from_date = $request->from_date != "" ? Carbon::parse($request->from_date) : Carbon::today();
        $to_date = $request->to_date != "" ? Carbon::parse($request->to_date) : Carbon::now();

        $transactions = Summary::select(DB::Raw('SUM(mpesa_amount) as mpesa, SUM(cash_amount) as cash'))
            ->whereBetween('trans_date', [$from_date, $to_date]);
        if ($sacco > 0) {
            $transactions = $transactions->whereHas('vehicle', function ($query) use ($sacco) {
                $query->where('sacco_id', $sacco);
            });
        }
        $transactions = $transactions->where(function ($q) use ($request) {
            $q->whereHas('vehicle', function ($query) use ($request) {
                $query->where('plate', 'LIKE', '%' . $request->search . '%');
            });
        });
        $transactions = $transactions->first();
        $mpesa = 0;
        $cash = 0;
        if ($transactions != null) {
            $mpesa = doubleval($transactions->mpesa);
            $cash = doubleval($transactions->cash);
        }
        return response()->json([
            'mpesa' => number_format($mpesa, 2),
            'cash' => number_format($cash, 2),
            'totals' => number_format($mpesa + $cash, 2)
        ]);
    }

    public function updateSummaries(Request $request)
    {
        if (auth()->user()->can('Add Summaries') || auth()->user()->can('Edit Summaries')) {
            $validator = Validator::make($request->all(), [
                'date' => 'required|date',
            ]);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }
            $transactions = Transaction::select('vehicle_id', DB::Raw('SUM(CASE WHEN mpesa_id>0 THEN amount ELSE 0 END) as mpesa_totals'), DB::Raw('SUM(CASE WHEN cash_id>0 THEN amount ELSE 0 END) as cash_totals'), DB::Raw('DATE(trans_date) as my_date'), DB::Raw('sum(CASE WHEN mpesa_id>0 THEN 1 END) as mpesa_txn'), DB::Raw('sum(CASE WHEN cash_id>0 THEN 1 ELSE 0 END) as cash_txn'))
                ->whereBetween('trans_date', [Carbon::parse($request->date), Carbon::parse($request->date)->addDay()])
                ->groupBy('vehicle_id', DB::Raw('DATE(trans_date)'))->get();
            foreach ($transactions as $transaction) {
                if ($transaction->vehicle_id != null) {
                    $summary = Summary::where('vehicle_id', $transaction->vehicle_id)->where('trans_date', $transaction->my_date)->first();
                    if ($summary == null) {
                        $summary = new Summary;
                    }
                    $summary->vehicle_id = $transaction->vehicle_id;
                    $summary->mpesa_amount = $transaction->mpesa_totals;
                    $summary->cash_amount = $transaction->cash_totals;
                    $summary->mpesa_txn = $transaction->mpesa_txn;
                    $summary->cash_txn = $transaction->cash_txn;
                    $summary->trans_date = $transaction->my_date;
                    $summary->save();
                }
            }
            return response()->json(['success' => 'Transactions for date ' . $request->date . ' summaries updated successfully!']);
        } else {
            return response()->json(['error' => 'You do not have permissions to Add/Edit Summaries'], 401);
        }
    }
}
