<?php

namespace App\Http\Controllers\Dashboard\Summaries;

use App\Models\Transaction;
use App\Models\Vehicle;
use App\Models\VehicleUser;
use DB;
use App\Models\Sacco;
use App\Http\Controllers\Controller;
use App\Models\Summary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Yajra\DataTables\DataTables;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;


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
        $from_date = $request->from_date != ""
            ? Carbon::parse($request->from_date)->startOfDay()
            : Carbon::today()->startOfDay();

        $to_date = $request->to_date != ""
            ? Carbon::parse($request->to_date)->endOfDay()
            : Carbon::now()->endOfDay();

        Log::info('===========================================================');
        Log::info('Request filters:', [
            'sacco' => $request->sacco ?? 'none',
            'search' => trim($request->search) ?? 'none',
        ]);
        Log::info('Get Summaries Called By User:', ['user_id' => auth()->id()]);
        Log::info('Date Range:', ['from' => $from_date->toDateTimeString(), 'to' => $to_date->toDateTimeString()]);

        // Start building the query
        $summaries = Summary::select(
            'vehicle_id',
            DB::raw('SUM(mpesa_amount) as mpesa_amount'),
            DB::raw('SUM(mpesa_txn) as mpesa_txn'),
            DB::raw('SUM(cash_amount) as cash_amount'),
            DB::raw('SUM(cash_txn) as cash_txn'),
            DB::raw('SUM(mpesa_amount + cash_amount) as totals'),
            DB::raw('SUM(mpesa_txn + cash_txn) as total_txn'),
            DB::raw('SUM(expense_fee_amount) as expense_fee_amount')
        )
            ->whereBetween('trans_date', [$from_date, $to_date])
            ->groupBy('vehicle_id');

        // Sacco filter
        if ($request->sacco > 0) {
            Log::info('************** sacco filter will be added ***********');
            $summaries->whereHas('vehicle', function ($query) use ($request) {
                $query->where('sacco_id', $request->sacco);
            });
        }

        // Get vehicles assigned to the user
        $vehicleIds = VehicleUser::where('user_id', auth()->id())
            ->where('status', true)
            ->pluck('vehicle_id');

        Log::info('Allowed vehicles:', ['vehicle_ids' => $vehicleIds]);

        // If user has vehicles assigned, apply filter
        if ($vehicleIds->count() > 0) {
            $summaries->whereIn('vehicle_id', $vehicleIds);
        } else {
            Log::warning('User has no assigned vehicles — skipping vehicle_id filter');
            // You can also return empty early if that’s your logic:
            // return DataTables::of(collect())->make();
        }

        // Apply search
        if (!empty(trim($request->search))) {
            $search = trim($request->search);
            $summaries->where(function ($q) use ($search) {
                $q->whereHas('vehicle', function ($query) use ($search) {
                    $query->where('plate', 'LIKE', "%$search%");
                })->orWhereHas('vehicle.sacco', function ($query) use ($search) {
                    $query->where('name', 'LIKE', "%$search%");
                });
            });
        }

        // Order by totals
        $summaries->orderBy('totals', 'DESC');

        // Get data
        DB::enableQueryLog();
        $data = $summaries->get();
        Log::info('Summary results count:', ['count' => $data->count()]);
        Log::info('Executed queries:', DB::getQueryLog());

        // ========== Manual Lazy Loading ========== //
        $vehicleMap = Vehicle::whereIn('id', $data->pluck('vehicle_id')->unique())
            ->get()
            ->keyBy('id');

        $saccoMap = Sacco::whereIn('id', $vehicleMap->pluck('sacco_id')->unique())
            ->get()
            ->keyBy('id');

        foreach ($vehicleMap as $vehicle) {
            $vehicle->setRelation('sacco', $saccoMap->get($vehicle->sacco_id));
        }

        foreach ($data as $summary) {
            $summary->setRelation('vehicle', $vehicleMap->get($summary->vehicle_id));
        }

        // ========== Format DataTables Output ========== //
        return DataTables::of($data)
            ->addColumn("transdate", fn() => now()->format('d M, Y')) // grouped data has no trans_date
            ->addColumn("plate", fn($row) => optional($row->vehicle)->plate ?? '-')
            ->addColumn("sacco", fn($row) => optional($row->vehicle->sacco)->name ?? '-')
            ->editColumn("mpesa_amount", fn($row) => number_format($row->mpesa_amount, 2, '.', ','))
            ->editColumn("mpesa_txn", fn($row) => number_format($row->mpesa_txn, 0, '.', ','))
            ->editColumn("cash_amount", fn($row) => number_format($row->cash_amount, 2, '.', ','))
            ->editColumn("cash_txn", fn($row) => number_format($row->cash_txn, 0, '.', ','))
            ->editColumn("totals", fn($row) => number_format($row->totals, 2, '.', ','))
            ->editColumn("total_txn", fn($row) => number_format($row->total_txn, 0, '.', ','))
            ->editColumn("expense_fee_amount", fn($row) => number_format($row->expense_fee_amount, 2, '.', ','))
            ->addIndexColumn()
            ->escapeColumns([])
            ->make();
    }

    public function getSummariesCards(Request $request)
    {
        $sacco = $request->sacco > 0 ? $request->sacco : "";
        $from_date = $request->from_date != "" ? Carbon::parse($request->from_date)->startOfDay() : Carbon::today()->startOfDay();
        $to_date = $request->to_date != "" ? Carbon::parse($request->to_date)->endOfDay() : Carbon::now()->endOfDay();

        $transactions = Summary::select(DB::Raw('SUM(mpesa_amount) as mpesa, SUM(cash_amount) as cash'))
            ->whereBetween('trans_date', [$from_date, $to_date]);
        if ($sacco > 0) {
            $transactions = $transactions->whereHas('vehicle', function ($query) use ($sacco) {
                $query->where('sacco_id', $sacco);
            });
        }

        $vehicles = VehicleUser::where('user_id', auth()->user()->id)
                ->where('status', true)->pluck('vehicle_id');
                if(count($vehicles)>0){
                    $transactions = $transactions->whereIn('vehicle_id', $vehicles);
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



