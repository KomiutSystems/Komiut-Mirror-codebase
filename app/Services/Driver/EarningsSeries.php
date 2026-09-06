<?php

declare(strict_types=1);

namespace App\Services\Driver;

use App\Models\Transaction;
use App\Services\Sql\DatePartSql;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * The shape of a window's takings over time — the numbers behind a sparkline.
 *
 * The earnings screen already showed today, this week, this month and all time
 * as four totals. A total says how much; it does not say whether the day is
 * building or already over, which is most of what a driver checking their phone
 * at 14:00 actually wants to know.
 *
 * THE SERIES MUST ADD UP TO THE HEADLINE. It is computed from the same
 * transactions table, over the same window bounds, split the same way
 * (mpesa_id > 0 / cash_id > 0) as takingsBetween — so the bars always sum to the
 * cash + mpesa figure printed above them. A sparkline that disagrees with the
 * number beside it is worse than no sparkline, because it makes the reader
 * distrust both.
 *
 * BUCKETS FOLLOW THE BUSINESS DAY, not the calendar. A matatu's day runs 03:00
 * to 03:00 (see BusinessDay), so the daily buckets are shifted by three hours:
 * a fare taken at 01:30 belongs to the night shift that started the previous
 * afternoon, and bucketing it at midnight would split one shift across two bars.
 *
 * EMPTY BUCKETS ARE INCLUDED AS ZERO. Skipping them would compress the quiet
 * hours and draw a busy afternoon as though it ran all day — the spacing carries
 * meaning, so the gaps have to be real.
 *
 * `trans_date` holds NAIROBI wall-clock while the window bounds arrive as app
 * instants, which is why every bound goes through BusinessDay::forLocalColumn
 * before it is bound, exactly as takingsBetween does.
 */
final class EarningsSeries
{
    public const HOURLY = 'hour';

    public const DAILY = 'day';

    public const MONTHLY = 'month';

    /**
     * How far back the all-time series will run.
     *
     * A sparkline of eighty points is a smudge, and a vehicle onboarded years
     * ago should not make the earnings screen pay for history nobody can read.
     */
    private const MAX_MONTHS = 36;

    /**
     * @return list<array{at: string, amount: float}>
     */
    public function build(int $vehicleId, ?Carbon $from, ?Carbon $to, string $granularity): array
    {
        [$start, $end] = $this->bounds($vehicleId, $from, $to, $granularity);

        if ($start === null || $end === null || $start->greaterThan($end)) {
            return [];
        }

        $totals = $this->totalsByBucket($vehicleId, $start, $end, $granularity);
        $series = [];

        foreach ($this->buckets($start, $end, $granularity) as $bucket) {
            $key = $bucket->format('Y-m-d H:i:s');

            $series[] = [
                'at' => $bucket->toIso8601String(),
                'amount' => round((float) ($totals[$key] ?? 0.0), 2),
            ];
        }

        return $series;
    }

    /**
     * The window to draw, in Nairobi wall-clock.
     *
     * An open-ended window (all time) is anchored to the vehicle's first payment
     * rather than to the epoch, so a bus that started in June does not get five
     * years of empty months in front of its data.
     */
    private function bounds(int $vehicleId, ?Carbon $from, ?Carbon $to, string $granularity): array
    {
        $end = BusinessDay::forLocalColumn($to ?? Carbon::now());

        if ($from !== null) {
            return [BusinessDay::forLocalColumn($from), $end];
        }

        $first = Transaction::where('vehicle_id', $vehicleId)->min('trans_date');

        if ($first === null) {
            return [null, null];
        }

        $start = Carbon::parse($first, BusinessDay::TIMEZONE);
        $earliest = $end->copy()->subMonths(self::MAX_MONTHS);

        return [$start->lessThan($earliest) ? $earliest : $start, $end];
    }

    /**
     * One grouped query per window: bucket start => cash + mpesa.
     *
     * Served by the (vehicle_id, trans_date) composite index — vehicle_id is the
     * equality predicate and trans_date the range, so this is an index range
     * scan over one bus rather than a walk of the whole money table.
     *
     * @return array<string, float>
     */
    private function totalsByBucket(int $vehicleId, CarbonInterface $start, CarbonInterface $end, string $granularity): array
    {
        $bucket = DatePartSql::truncate('trans_date', $granularity, $this->shiftHours($granularity));

        return Transaction::where('vehicle_id', $vehicleId)
            ->where('trans_date', '>=', $start)
            ->where('trans_date', '<=', $end)
            ->selectRaw("{$bucket} as bucket")
            // The same split takingsBetween uses, so the series sums to the
            // headline. A transaction attached to neither an M-Pesa payment nor
            // a cash submission counts toward neither.
            ->selectRaw('COALESCE(SUM(CASE WHEN mpesa_id > 0 OR cash_id > 0 THEN amount ELSE 0 END), 0) as amount')
            ->groupByRaw($bucket)
            ->get()
            ->mapWithKeys(fn ($row) => [
                Carbon::parse($row->bucket)->format('Y-m-d H:i:s') => (float) $row->amount,
            ])
            ->all();
    }

    /**
     * Every bucket start in the window, oldest first, including empty ones.
     *
     * @return list<CarbonInterface>
     */
    private function buckets(CarbonInterface $start, CarbonInterface $end, string $granularity): array
    {
        $cursor = $this->floor($start, $granularity);
        $buckets = [];

        while ($cursor->lessThanOrEqualTo($end)) {
            $buckets[] = $cursor->copy();
            $cursor = match ($granularity) {
                self::HOURLY => $cursor->addHour(),
                self::DAILY => $cursor->addDay(),
                self::MONTHLY => $cursor->addMonth(),
            };
        }

        return $buckets;
    }

    /** The bucket a moment falls in, matching the SQL truncation exactly. */
    private function floor(CarbonInterface $at, string $granularity): CarbonInterface
    {
        $shift = $this->shiftHours($granularity);
        $shifted = $at->copy()->subHours($shift);

        $floored = match ($granularity) {
            self::HOURLY => $shifted->startOfHour(),
            self::DAILY => $shifted->startOfDay(),
            self::MONTHLY => $shifted->startOfMonth(),
        };

        return $floored->addHours($shift);
    }

    /**
     * Daily buckets carry the 03:00 business-day offset; hourly and monthly do
     * not. An hour is an hour whatever the day boundary, and a month rolls on
     * the 1st.
     */
    private function shiftHours(string $granularity): int
    {
        return $granularity === self::DAILY ? BusinessDay::START_HOUR : 0;
    }
}
