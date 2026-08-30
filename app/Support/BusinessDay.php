<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The operator's business day: 03:00 EAT to 03:00 the next morning.
 *
 * A matatu day does not end at midnight. The last runs of the night belong to
 * the day they started, and the till is reconciled at first light — so a day's
 * takings run from 03:00 Africa/Nairobi to 03:00 the following morning, a
 * half-open [start, end) window.
 *
 * WHY THIS DOES NOT READ config('app.timezone'):
 * Nairobi is a FIXED offset of +03:00 with no daylight saving, ever. That makes
 * 03:00 EAT exactly 00:00 UTC. The app is currently configured `timezone=UTC`,
 * so Carbon::today() (UTC midnight) already lands on the 03:00-EAT boundary and
 * the numbers are correct today by coincidence. This class makes that boundary
 * EXPLICIT — anchored to Africa/Nairobi rather than to whatever the app timezone
 * happens to be — so that a future change to config('app.timezone') cannot
 * silently shift the business day off 03:00 EAT. The window is always computed
 * in Nairobi; only the returned instants are expressed in the app timezone, so
 * Eloquent binds them against timestamp columns exactly as before.
 */
final class BusinessDay
{
    /** The operator's wall-clock timezone. Fixed +03:00, no DST. */
    public const TIMEZONE = 'Africa/Nairobi';

    /** The hour (Nairobi local) at which one business day rolls into the next. */
    public const START_HOUR = 3;

    /**
     * The [start, end) window of the business day containing $at (default: now).
     *
     * Half-open: `start` is inclusive, `end` (03:00 the next morning) is
     * exclusive, so a row timestamped at exactly the boundary is counted into
     * one day only. Both instants are returned as app-timezone Carbon objects
     * so Eloquent binds them correctly against timestamp columns; under the
     * current UTC config this is byte-for-byte the old
     * [date->startOfDay(), +1 day] pair, because 03:00 EAT == 00:00 UTC.
     *
     * @return array{0: Carbon, 1: Carbon} [start, end]
     */
    public static function windowFor(?CarbonInterface $at = null): array
    {
        $local = ($at !== null ? Carbon::instance($at) : Carbon::now())
            ->copy()->setTimezone(self::TIMEZONE);

        // Shift back by the start hour so that startOfDay() lands on the correct
        // business date even between midnight and 03:00, then shift forward to
        // the 03:00 boundary.
        $businessDate = $local->copy()->subHours(self::START_HOUR)->startOfDay();

        $start = $businessDate->copy()->addHours(self::START_HOUR);
        $end = $start->copy()->addDay();

        $appTimezone = self::appTimezone();

        return [$start->setTimezone($appTimezone), $end->setTimezone($appTimezone)];
    }

    /**
     * The same instant, expressed in Nairobi wall-clock, for binding against a
     * column that STORES Nairobi wall-clock.
     *
     * `transactions.trans_date` is written straight from M-Pesa's `TransTime`
     * (C2bPaymentRecorder), which is EAT local time, not UTC. Binding a
     * UTC-expressed bound against it is three hours out: the 03:00 EAT boundary
     * arrives as the string "00:00:00", so the window silently becomes the EAT
     * CALENDAR day and every payment between midnight and 03:00 falls outside
     * the business day it belongs to. A driver finishing a late run saw the
     * night's takings vanish from "today".
     *
     * Only the string representation changes; the instant is identical.
     */
    public static function forLocalColumn(Carbon $at): Carbon
    {
        return $at->copy()->setTimezone(self::TIMEZONE);
    }

    /**
     * The business date of the day containing $at, as Nairobi-midnight.
     *
     * Returned at 00:00 Africa/Nairobi so that ->toDateString() yields the
     * calendar date the business day is filed under — the stable component of a
     * per-day cache key. Immutable so a caller cannot mutate the shared anchor.
     */
    public static function current(?CarbonInterface $at = null): CarbonImmutable
    {
        $local = ($at !== null ? CarbonImmutable::instance($at) : CarbonImmutable::now())
            ->setTimezone(self::TIMEZONE);

        return $local->subHours(self::START_HOUR)->startOfDay();
    }

    /**
     * The app timezone, or UTC if the container is not booted.
     *
     * Guarded so the pure unit test can exercise the Nairobi maths without a
     * Laravel application; the window is computed in Nairobi regardless, so this
     * only decides how the returned instants are re-expressed.
     */
    private static function appTimezone(): string
    {
        try {
            $timezone = config('app.timezone');
        } catch (\Throwable $e) {
            return 'UTC';
        }

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }
}
