<?php

namespace App\Http\Controllers\APIs\Dashboard;

use App\Http\Controllers\Concerns\ScopesToOwnedVehicles;
use App\Http\Controllers\Controller;
use App\Models\Summary;
use App\Models\Transaction;
use App\Services\Sql\DatePartSql;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class HomeAPIController extends Controller
{
    use ScopesToOwnedVehicles;

    /**
     * The clock the money is filed under.
     *
     * `transactions.trans_date` is M-Pesa's TransTime — Nairobi wall-clock — and
     * `summaries.trans_date` is the Nairobi CALENDAR date of it
     * (C2bPaymentRecorder::rollIntoSummary formats TransTime as Y-m-d). The app
     * runs UTC, so Carbon::today() is the Nairobi date only from 03:00 EAT
     * onwards: between midnight and 03:00 the dashboard still said "yesterday",
     * the today tile read the previous day's takings, and on the 1st of a month
     * "this month" was still last month for three hours. Every window here is
     * anchored on the Nairobi date instead. Kenya has no DST, so this is exact.
     */
    private const PAYMENT_CLOCK = 'Africa/Nairobi';

    /** The `year` query parameter. The name is legacy; it selects the period. */
    private const WEEK = 0;

    private const MONTH = 1;

    private const THREE_MONTHS = 2;

    private const SIX_MONTHS = 3;

    private const YEAR_TO_DATE = 4;

    private const MONTH_LABELS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function getDashboard(Request $request)
    {
        $sacco = $request->sacco > 0 ? $request->sacco : '';

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

        $vehicles = explode(',', str_replace(']', '', str_replace('[', '', (string) $request->vehicles)));
        $all_vehicles = [];

        foreach ($vehicles as $vehicle) {
            $v = trim($vehicle);
            if ($v != '') {
                array_push($all_vehicles, $v);
            }
        }

        // `year` was compared loosely before (a missing value == 0 was the
        // week); anything outside the five buttons now also means the week,
        // rather than a week-long window charted by month.
        $period = $request->integer('year');
        if ($period < self::WEEK || $period > self::YEAR_TO_DATE) {
            $period = self::WEEK;
        }

        $today = Carbon::now(self::PAYMENT_CLOCK)->startOfDay();
        [$start_date, $end_date, $xaxis] = $this->window($period, $today);

        // TODAY, genuinely today — its own one-day window, read from
        // TRANSACTIONS, and it is also the live slice of every period below.
        //
        // Why not summaries for today as well: summaries are exact for a CLOSED
        // day but lag on the open one. Cash reaches `summaries` only through the
        // five-minute app:generate-vehicle-summaries recompute (nothing else
        // ever writes a non-zero cash_amount), and that command picks "today"
        // with Carbon::today() — the UTC date — so between 00:00 and 03:00 EAT
        // the new Nairobi day's cash is not summarised at all. The tile is the
        // number a SACCO checks against the conductor's book; it must be exact.
        // One day of raw rows is a bounded range read on the trans_date index,
        // not the months-long scan the periods used to be.
        //
        // Half-open [00:00, next 00:00) in Nairobi wall-clock, bound as strings
        // against a column that STORES Nairobi wall-clock. The old window was an
        // inclusive whereBetween, which also counted a payment at exactly the
        // next midnight into today.
        //
        // `mpesa`, `cash` and `totals` further down are the SELECTED PERIOD's
        // takings, and always were. The payload never said so, so the dashboard
        // labelled them "Collected today" and the tile moved every time somebody
        // changed the chart period. On 29 Aug NICCO had actually taken
        // KES 724,858; the tile read 16,888,522. `today` and `period` sit beside
        // the old keys so a caller can be precise about which number it shows.
        //
        // attributed(): a payment we could not match to a bus is not takings.
        // Unscoped callers (superadmin) reach this with no sacco filter, and the
        // SACCO's nightly till-to-bank sweeps land as C2B on shortcodes that
        // belong to no vehicle — KES 483,268 of phantom revenue on 31 Aug alone.
        $todayRow = $this->narrowed(Transaction::attributed(), $sacco, $all_vehicles, $ownedVehicleIds)
            ->selectRaw('SUM(CASE WHEN mpesa_id > 0 THEN amount ELSE 0 END) as mpesa, SUM(CASE WHEN cash_id > 0 THEN amount ELSE 0 END) as cash')
            ->where('trans_date', '>=', $today->toDateTimeString())
            ->where('trans_date', '<', $today->copy()->addDay()->toDateTimeString())
            ->first();

        $todayMpesa = (float) ($todayRow->mpesa ?? 0);
        $todayCash = (float) ($todayRow->cash ?? 0);

        // THE PERIOD, from the daily rollup instead of raw transactions.
        //
        // Every period figure used to be a SUM over `transactions`, which holds
        // one row per fare. Measured read-only on production: a six-month window
        // is 5.8M rows and took 5.0 s for NICCO's SACCO admin and 8.6 s for a
        // superadmin — and the endpoint ran it TWICE per load (the chart series,
        // then the totals), on every period click and on the web dashboard's
        // 60-second refetch. `summaries` holds one row per vehicle per Nairobi
        // day; the same totals from it take 6–22 ms and agree to the shilling.
        // It is kept current live by C2bPaymentRecorder::rollIntoSummary and
        // recomputed from `transactions` every five minutes, and summaries ==
        // attributed transactions per day was checked on every day Apr–Sep 2026.
        //
        // One grouped query now serves both the series and the totals (the
        // buckets are summed in PHP), so a period costs one rollup read plus the
        // one-day read above, where it used to cost two full scans plus that.
        //
        // TODAY IS EXCLUDED from the rollup and taken from $todayRow instead,
        // for the reason given there. Without the exclusion today would count
        // twice; without the substitution the period would lag the tile beside
        // it. Days after today stay inside the window, as they always did.
        //
        // No attributed() here because none is needed: a summary row always
        // names a vehicle — the recorder only rolls up a payment it attributed,
        // and the recompute skips null vehicle_id.
        //
        // Tenancy is Summary's own global scopes (SaccoScope, BrandScope,
        // FinancierScope, all reached through `vehicle`, exactly as on
        // Transaction), never bypassed; the request's filters and the investor
        // narrowing are applied by the same narrowed() as the today read.
        $bucket = $this->bucketSql($period);

        $rows = $this->narrowed(Summary::query(), $sacco, $all_vehicles, $ownedVehicleIds)
            ->selectRaw("{$bucket['select']}, SUM(summaries.mpesa_amount) as mpesa, SUM(summaries.cash_amount) as cash")
            ->whereBetween('summaries.trans_date', [$start_date->toDateString(), $end_date->toDateString()])
            ->where('summaries.trans_date', '<>', $today->toDateString())
            ->groupByRaw($bucket['group'])
            ->get();

        $mpesa = 0.0;
        $cash = 0.0;
        $series = [];

        foreach ($rows as $row) {
            $rowMpesa = (float) $row->mpesa;
            $rowCash = (float) $row->cash;
            $mpesa += $rowMpesa;
            $cash += $rowCash;

            $point = $this->point($period, $rowMpesa + $rowCash, $row->day ?? null, $row->year ?? null, $row->month ?? null);
            $series[$this->pointKey($period, $point)] = $point;
        }

        // Every period contains today today; the check keeps a future window
        // that does not (a "last month" button) from being handed today's money.
        if ($today->betweenIncluded($start_date, $end_date)) {
            $mpesa += $todayMpesa;
            $cash += $todayCash;

            $todayTotal = $todayMpesa + $todayCash;
            // format('l') is the English day name whatever the locale, which is
            // what DatePartSql::dayName returns from the database.
            $todayDay = $period === self::WEEK ? $today->format('l') : $today->day;
            $todayPoint = $this->point($period, $todayTotal, $todayDay, $today->year, $today->month);
            $key = $this->pointKey($period, $todayPoint);

            if (isset($series[$key])) {
                $series[$key]['totals'] += $todayTotal;
            } elseif ($todayTotal != 0.0) {
                $series[$key] = $todayPoint;
            }
        }

        $transactions = json_encode($this->ordered($period, $series));

        return response()->json([
            'mpesa' => $mpesa, 'cash' => $cash,
            'totals' => $mpesa + $cash, 'transactions' => $transactions, 'xaxis' => json_encode($xaxis),

            // Unambiguous, and named for the window they actually cover.
            'today' => [
                'date' => $today->toDateString(),
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

    /**
     * The request's filters and the investor boundary, applied identically to
     * the today read (Transaction) and the period read (Summary).
     *
     * $vehicleFilter is a FILTER the caller chose: empty means "all my buses",
     * so it is skipped when empty. $ownedVehicleIds is a BOUNDARY: null means
     * "no ownership narrowing", and an empty array must narrow to nothing — it
     * is passed straight to whereIn, which compiles [] to 0 = 1. Never guard it
     * with count().
     *
     * @param  array<int, string>  $vehicleFilter
     * @param  array<int, int>|null  $ownedVehicleIds
     */
    private function narrowed(Builder $query, mixed $sacco, array $vehicleFilter, ?array $ownedVehicleIds): Builder
    {
        if ($sacco > 0) {
            $query->whereHas('vehicle', function ($q) use ($sacco) {
                $q->where('sacco_id', $sacco);
            });
        }

        if (count($vehicleFilter) > 0) {
            $query->whereIn($query->qualifyColumn('vehicle_id'), $vehicleFilter);
        }

        if ($ownedVehicleIds !== null) {
            $query->whereIn($query->qualifyColumn('vehicle_id'), $ownedVehicleIds);
        }

        return $query;
    }

    /**
     * The period's [from, to] (Nairobi dates) and the chart's x-axis labels.
     *
     * Week is Carbon's startOfWeek()/endOfWeek() under the app locale (`en`,
     * first_day_of_week = 1 in Carbon 3): Monday to Sunday. The axis has always
     * been listed Sunday-first; the web dashboard keys the series by day NAME,
     * so the order of the labels is presentation only.
     *
     * @return array{0: Carbon, 1: Carbon, 2: array<int, string>}
     */
    private function window(int $period, Carbon $today): array
    {
        switch ($period) {
            case self::MONTH:
                $from = $today->copy()->startOfMonth();
                $to = $today->copy()->endOfMonth();

                // Every day of the month, the last one included. The old loop
                // stopped one short (`$i < $end_day`), so the 30th/31st never
                // had a label and the web chart, which draws one bar per label,
                // dropped that day's takings on the last day of every month.
                $xaxis = [];
                for ($i = 1; $i <= $to->day; $i++) {
                    $xaxis[] = sprintf('%02d', $i);
                }

                return [$from, $to, $xaxis];

            case self::THREE_MONTHS:
                // The current month and the two before it, whole months.
                $from = $today->copy()->startOfMonth()->subMonths(2);

                return [$from, $today->copy()->endOfMonth(), $this->monthLabels($from, 3)];

            case self::SIX_MONTHS:
                // The current month and the five before it, whole months.
                $from = $today->copy()->startOfMonth()->subMonths(5);

                return [$from, $today->copy()->endOfMonth(), $this->monthLabels($from, 6)];

            case self::YEAR_TO_DATE:
                // January to the end of this month; the axis shows the whole year.
                $from = $today->copy()->startOfYear();

                return [$from, $today->copy()->endOfMonth(), $this->monthLabels($from, 12)];

            default:
                return [
                    $today->copy()->startOfWeek(),
                    $today->copy()->endOfWeek(),
                    ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
                ];
        }
    }

    /** @return array<int, string> $count consecutive month labels starting at $from's month. */
    private function monthLabels(Carbon $from, int $count): array
    {
        $labels = [];
        for ($i = 0; $i < $count; $i++) {
            $labels[] = self::MONTH_LABELS[($from->month - 1 + $i) % 12];
        }

        return $labels;
    }

    /**
     * The grouping expression for the period's buckets, over a DATE column.
     *
     * DatePartSql on a DATE gives what it gave on the old TIMESTAMP: the
     * calendar parts of a date are the same either way, and on pgsql
     * to_char(date, 'FMDay') resolves through the implicit date→timestamptz
     * cast at local midnight and is rendered in the same zone, so the day name
     * cannot shift.
     *
     * @return array{select: string, group: string}
     */
    private function bucketSql(int $period): array
    {
        $column = 'summaries.trans_date';

        if ($period === self::WEEK) {
            $dayName = DatePartSql::dayName($column);

            return ['select' => "{$dayName} as day", 'group' => $dayName];
        }

        if ($period === self::MONTH) {
            $dayOfMonth = DatePartSql::dayOfMonth($column);

            return ['select' => "{$dayOfMonth} as day", 'group' => $dayOfMonth];
        }

        $year = DatePartSql::year($column);
        $month = DatePartSql::month($column);

        return ['select' => "{$year} as year, {$month} as month", 'group' => "{$year}, {$month}"];
    }

    /**
     * One row of the `transactions` series, in the shape the dashboard reads:
     * {totals, day} for the week (day NAME) and the month (day of month), and
     * {totals, year, month} for everything longer — keys in that order, as the
     * old Eloquent toJson() emitted them.
     *
     * day/year/month are ints and totals a float whatever the driver returned:
     * pgsql hands EXTRACT back as a numeric STRING, and a bucket built in PHP for
     * today has to match the ones read from SQL. The web dashboard runs every
     * one of these through Number(), so either form reads the same.
     *
     * On the wire a whole-number total is written without a fraction (300.0
     * goes out as `300`): the series is json_encode()d with no flags, as the
     * old toJson() was. Deliberately no JSON_PRESERVE_ZERO_FRACTION: it would
     * change the bytes of every whole-shilling bucket for no reader's benefit,
     * and the top-level mpesa/cash/totals (response()->json, no flags either)
     * would still say `300`. A reader must not tell int from float by the JSON.
     *
     * $day is the day NAME for the week and the day of month for the month;
     * $year and $month are read only for the longer periods.
     *
     * @return array<string, float|int|string>
     */
    private function point(int $period, float $total, mixed $day, mixed $year, mixed $month): array
    {
        if ($period === self::WEEK) {
            return ['totals' => $total, 'day' => trim((string) $day)];
        }

        if ($period === self::MONTH) {
            return ['totals' => $total, 'day' => (int) $day];
        }

        return ['totals' => $total, 'year' => (int) $year, 'month' => (int) $month];
    }

    /** @param  array<string, float|int|string>  $point */
    private function pointKey(int $period, array $point): string
    {
        return match ($period) {
            self::WEEK => (string) $point['day'],
            self::MONTH => sprintf('%02d', $point['day']),
            default => sprintf('%04d-%02d', $point['year'], $point['month']),
        };
    }

    /**
     * The old ORDER BY, kept: day name alphabetically for the week, day of
     * month for the month. Longer periods are ordered by year THEN month — the
     * old query ordered by month alone, which put January ahead of November in
     * a window spanning New Year. The web dashboard keys by month, so only an
     * index-based reader could tell, and chronological is the order it wants.
     *
     * @param  array<string, array<string, float|int|string>>  $series
     * @return array<int, array<string, float|int|string>>
     */
    private function ordered(int $period, array $series): array
    {
        $rows = array_values($series);

        usort($rows, static function (array $a, array $b) use ($period): int {
            return match ($period) {
                self::WEEK => strcmp((string) $a['day'], (string) $b['day']),
                self::MONTH => $a['day'] <=> $b['day'],
                default => [$a['year'], $a['month']] <=> [$b['year'], $b['month']],
            };
        });

        return $rows;
    }
}
