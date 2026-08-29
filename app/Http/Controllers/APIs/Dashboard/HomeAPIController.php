<?php

namespace App\Http\Controllers\APIs\Dashboard;

use App\Http\Controllers\Concerns\ScopesToOwnedVehicles;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\Sql\DatePartSql;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HomeAPIController extends Controller
{
    use ScopesToOwnedVehicles;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function getDashboard(Request $request){
        $sacco = $request->sacco > 0?$request->sacco:"";

        // An investor holds View Transactions, and this endpoint reports the
        // SACCO's takings. Without it they see NICCO's whole daily figure on the
        // landing tiles — the same leak the listings were narrowed for, one
        // screen to the left.
        //
        // NULL means "not investor-only", so every other caller's query is
        // byte-identical to before. An EMPTY array is passed through UNGATED and
        // compiles to 0 = 1: an investor who owns nothing must see nothing, and
        // `if (count($ids) > 0)` is exactly the fail-open shape this exists to
        // remove.
        $ownedVehicleIds = $this->ownedVehicleIds();
        $today = Carbon::today();
        $start_date = $today->copy()->startOfWeek();
        $end_date = $today->copy()->endOfWeek();

        $vehicles = explode(',', str_replace(']', '', str_replace('[', '', $request->vehicles)));
        $all_vehicles = [];

        foreach ($vehicles as $vehicle) {
            $v = trim($vehicle);
            if($v != ""){
                array_push($all_vehicles, trim($vehicle));
            }
        }
        $xaxis = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
        $months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];

        if($request->year == 1){
            $start_date = $today->copy()->startOfMonth();
            $end_date = $today->copy()->endOfMonth();
            $start_day = intval($start_date->format('d'));
            $end_day = intval($end_date->format('d'));
            $xaxis = [];
            for($i=$start_day; $i < $end_day; $i++){
                array_push($xaxis, sprintf('%02d', $i));
            }
        }

        if($request->year == 2){
            $start_date = $today->copy()->startOfMonth()->subMonths(2);
            $end_date = $today->copy()->endOfMonth();
            $xaxis = [];
            $index = intval($start_date->format('m')) - 1;
            for($i=0; $i < 3; $i++){
                if($index > 11){
                    $index = 0;
                }
                array_push($xaxis, $months[$index]);
                $index++;
            }
        }
        if($request->year == 3){
            $start_date = $today->copy()->startOfMonth()->subMonths(5);
            $end_date = $today->copy()->endOfMonth();$xaxis = [];
            $index = intval($start_date->format('m')) - 1;
            for($i=0; $i < 6; $i++){
                if($index > 11){
                    $index = 0;
                }
                array_push($xaxis, $months[$index]);
                $index++;
            }
        }
        if($request->year == 4){
            $start_date = $today->copy()->startOfYear();
            $end_date = $today->copy()->endOfMonth();$xaxis = [];

            $index = intval($start_date->format('m')) - 1;
            for($i=0; $i < 12; $i++){
                if($index > 11){
                    $index = 0;
                }
                array_push($xaxis, $months[$index]);
                $index++;
            }
        }
        if($request->year == 0){
            $dayName = DatePartSql::dayName('trans_date');
            $transactions = Transaction::select(DB::raw('SUM(amount) as totals'), DB::raw("{$dayName} as day"))
            ->whereBetween('trans_date', [$start_date, $end_date]);
            if($sacco > 0){
                $transactions = $transactions->whereHas('vehicle', function($query) use ($sacco){
                    $query->where('sacco_id', $sacco);
                });
            }
            if(count($all_vehicles)>0){
                $transactions = $transactions->whereIn('vehicle_id', $all_vehicles);
            }
            if ($ownedVehicleIds !== null) {
                $transactions = $transactions->whereIn('vehicle_id', $ownedVehicleIds);
            }
            $transactions = $transactions->groupby(DB::raw($dayName))->orderBy(DB::raw($dayName), 'ASC')->get()->toJson();
        }else if($request->year == 1){
            $dayOfMonth = DatePartSql::dayOfMonth('trans_date');
            $transactions = Transaction::select(DB::raw('SUM(amount) as totals'), DB::raw("{$dayOfMonth} as day"))
            ->whereBetween('trans_date', [$start_date, $end_date]);
            if($sacco > 0){
                $transactions = $transactions->whereHas('vehicle', function($query) use ($sacco){
                    $query->where('sacco_id', $sacco);
                });
            }
            if(count($all_vehicles)>0){
                $transactions = $transactions->whereIn('vehicle_id', $all_vehicles);
            }
            if ($ownedVehicleIds !== null) {
                $transactions = $transactions->whereIn('vehicle_id', $ownedVehicleIds);
            }
            $transactions = $transactions->groupby(DB::raw($dayOfMonth))->orderBy(DB::raw($dayOfMonth), 'ASC')->get()->toJson();
        } else {
            $year = DatePartSql::year('trans_date');
            $month = DatePartSql::month('trans_date');
            $transactions = Transaction::select(DB::raw('SUM(amount) as totals'), DB::raw("{$year} as year, {$month} as month"))
                ->whereBetween('trans_date', [$start_date, $end_date]);
                if($sacco > 0){
                    $transactions = $transactions->whereHas('vehicle', function($query) use ($sacco){
                        $query->where('sacco_id', $sacco);
                    });
                }
                if(count($all_vehicles)>0){
                    $transactions = $transactions->whereIn('vehicle_id', $all_vehicles);
                }
                if ($ownedVehicleIds !== null) {
                    $transactions = $transactions->whereIn('vehicle_id', $ownedVehicleIds);
                }
                $transactions = $transactions->groupby(DB::raw($year), DB::raw($month))->orderBy(DB::raw($month), 'ASC')->get()->toJson();
        }

        $ctransactions = Transaction::select(DB::Raw('SUM(CASE WHEN mpesa_id > 0 THEN amount ELSE 0 END) as mpesa, SUM(CASE WHEN cash_id > 0 THEN amount ELSE 0 END) as cash'))
                ->whereBetween('trans_date', [$start_date, $end_date]);
        if($sacco > 0){
            $ctransactions = $ctransactions->whereHas('vehicle', function($query) use ($sacco){
                $query->where('sacco_id', $sacco);
            });
        }
        if(count($all_vehicles)>0){
            $ctransactions = $ctransactions->whereIn('vehicle_id', $all_vehicles);
        }
        if ($ownedVehicleIds !== null) {
            $ctransactions = $ctransactions->whereIn('vehicle_id', $ownedVehicleIds);
        }
        $ctransactions = $ctransactions->first();
        $mpesa = 0;
        $cash = 0;
        if($ctransactions != null){
            $mpesa = doubleval($ctransactions->mpesa);
            $cash = doubleval($ctransactions->cash);
        }
        // TODAY, genuinely today — a separate query with its own one-day window.
        //
        // `mpesa`, `cash` and `totals` below are the SELECTED PERIOD's takings,
        // and always were: they use [$start_date, $end_date], which is the week,
        // the month, three months or six months depending on the buttons. The
        // payload never said so, so the dashboard labelled them "Collected
        // today" and the tile moved every time somebody changed the chart
        // period. On 29 Aug NICCO had actually taken KES 724,858; the tile read
        // 16,888,522.
        //
        // The old keys are untouched — the dashboard renders against them — and
        // these are added beside them so a caller can be precise about which
        // number it is showing.
        $todayStart = Carbon::today();
        $todayEnd = $todayStart->copy()->addDay();

        $todayRow = Transaction::select(DB::Raw('SUM(CASE WHEN mpesa_id > 0 THEN amount ELSE 0 END) as mpesa, SUM(CASE WHEN cash_id > 0 THEN amount ELSE 0 END) as cash'))
            ->whereBetween('trans_date', [$todayStart, $todayEnd]);

        if ($sacco > 0) {
            $todayRow = $todayRow->whereHas('vehicle', function ($query) use ($sacco) {
                $query->where('sacco_id', $sacco);
            });
        }
        if (count($all_vehicles) > 0) {
            $todayRow = $todayRow->whereIn('vehicle_id', $all_vehicles);
        }
        // Narrowed for an investor exactly like every other figure here.
        if ($ownedVehicleIds !== null) {
            $todayRow = $todayRow->whereIn('vehicle_id', $ownedVehicleIds);
        }

        $todayRow = $todayRow->first();
        $todayMpesa = (float) ($todayRow->mpesa ?? 0);
        $todayCash = (float) ($todayRow->cash ?? 0);

        return response()->json([
            'mpesa'=>$mpesa, 'cash'=>$cash,
            'totals'=>$mpesa+$cash, 'transactions'=>$transactions,"xaxis"=>json_encode($xaxis),

            // Unambiguous, and named for the window they actually cover.
            'today' => [
                'date' => $todayStart->toDateString(),
                'mpesa' => $todayMpesa,
                'cash' => $todayCash,
                'total' => $todayMpesa + $todayCash,
            ],
            'period' => [
                // Stated so a tile can say WHICH window it is showing rather
                // than the client having to infer it from the button it pressed.
                'from' => $start_date->toDateString(),
                'to' => $end_date->toDateString(),
                'mpesa' => (float) $mpesa,
                'cash' => (float) $cash,
                'total' => (float) $mpesa + (float) $cash,
            ],
        ]);
    }

}
