<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Driver;

use App\Http\Controllers\Concerns\ResolvesDriverVehicle;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @group Driver — the app's home, earnings and expenses
 *
 * Where the caller's bus stands in its SACCO.
 *
 * RANK ONLY. Every bus is listed by plate and position; the only money in the
 * response is the caller's own bus's. Owners do not want their takings read by
 * other crews, and a ranking does not need anyone else's figure.
 *
 * Same order as the office's summaries/overview: summaries, M-Pesa + cash,
 * highest first, over the same calendar day / Monday week / month windows.
 *
 * PER SACCO, across brands: NICCO's komiut and safiri buses are one fleet.
 */
final class DriverLeaderboardController extends Controller
{
    use ResolvesDriverVehicle;

    private const DEFAULT_LIMIT = 20;

    private const MAX_LIMIT = 200;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * SACCO leaderboard
     *
     * @queryParam period string day, week or month. Default week. Example: week
     * @queryParam limit integer How many buses to list, 1-200. Default 20. Example: 20
     * @queryParam vehicle_id integer Owners only: which of their buses is "you". Example: 784
     */
    public function index(Request $request): JsonResponse
    {
        $vehicle = $this->vehicle($request->filled('vehicle_id') ? (int) $request->input('vehicle_id') : null);
        if ($vehicle === null) {
            return $this->noAssignment();
        }
        if ($vehicle->sacco_id === null) {
            return response()->json(['error' => 'This bus does not belong to a SACCO.'], 404);
        }

        $period = (string) $request->input('period', 'week');
        if (! in_array($period, ['day', 'week', 'month'], true)) {
            return response()->json(['error' => 'period must be day, week or month'], 400);
        }
        $limit = min(max((int) $request->input('limit', self::DEFAULT_LIMIT), 1), self::MAX_LIMIT);

        // Same "today" and windows as SummariesAPIController::overview.
        $today = Carbon::now('Africa/Nairobi')->startOfDay()->shiftTimezone(config('app.timezone'));
        [$from, $to] = match ($period) {
            'day' => [$today->copy(), $today->copy()->addDay()],
            'week' => [$today->copy()->startOfWeek(Carbon::MONDAY), $today->copy()->startOfWeek(Carbon::MONDAY)->addWeek()],
            'month' => [$today->copy()->startOfMonth(), $today->copy()->startOfMonth()->addMonth()],
        };

        // Raw table, not the Summary model: its brand scope would split a
        // two-brand SACCO, and the SACCO is the boundary asked for here.
        $rows = DB::table('summaries')
            ->join('vehicles', 'vehicles.id', '=', 'summaries.vehicle_id')
            ->where('vehicles.sacco_id', $vehicle->sacco_id)
            ->where('summaries.trans_date', '>=', $from)
            ->where('summaries.trans_date', '<', $to)
            ->groupBy('summaries.vehicle_id', 'vehicles.plate', 'vehicles.fleet_no')
            ->havingRaw('SUM(summaries.mpesa_amount + summaries.cash_amount) > 0')
            ->orderByRaw('SUM(summaries.mpesa_amount + summaries.cash_amount) DESC')
            ->orderBy('vehicles.plate')
            ->get([
                'summaries.vehicle_id',
                'vehicles.plate',
                'vehicles.fleet_no',
                DB::raw('SUM(summaries.mpesa_amount + summaries.cash_amount) AS collections'),
            ])
            ->values();

        $mine = $rows->search(fn ($r) => (int) $r->vehicle_id === (int) $vehicle->id);

        return response()->json([
            'period' => $period,
            'from' => $from->toDateString(),
            'to' => $to->copy()->subDay()->toDateString(),
            'sacco' => optional($vehicle->sacco)->name,
            'ranked' => $rows->count(),
            'you' => [
                'vehicle_id' => (int) $vehicle->id,
                'plate' => $vehicle->plate,
                // Null until the bus has taken something this period.
                'rank' => $mine === false ? null : $mine + 1,
                'collections' => $mine === false ? 0.0 : (float) $rows[$mine]->collections,
            ],
            // Deliberately no amounts here.
            'vehicles' => $rows->take($limit)->map(fn ($r, $i) => [
                'rank' => $i + 1,
                'plate' => $r->plate,
                'fleet_no' => $r->fleet_no,
                'is_you' => (int) $r->vehicle_id === (int) $vehicle->id,
            ])->values(),
        ]);
    }
}
