<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Reads a `trans_date` whatever shape it arrives in.
 *
 * WHY THIS EXISTS. `transactions.trans_date` and
 * `vehicle_expense_and_fees.trans_date` are NOT cast on their models, so what
 * Eloquent hands back is a plain string from the driver. Three call sites did
 * `optional($t->trans_date)->toIso8601String()`, and `optional()` on a string
 * returns null the moment the method does not exist on it -- so every one of
 * them emitted `null` for a timestamp that was sitting right there in the row.
 *
 * The visible damage: every payment in the driver app carried `"at": null`, and
 * the Date column of the dashboard's transactions CSV export was blank on every
 * line.
 *
 * WHY NOT JUST CAST THE COLUMN. Adding `'trans_date' => 'datetime'` to
 * Transaction looks like the obvious fix and is not safe here. The dashboard
 * endpoint returns Transaction models RAW (`'transactions' => $results`), so a
 * cast changes the wire format the live dashboard already parses, from
 * `2026-09-06 11:00:00` to `2026-09-06T11:00:00.000000Z`. The same endpoint also
 * builds its keyset pagination cursor out of `trans_date`, so the encoded cursor
 * would change shape mid-flight for anyone holding one. Repairing the readers
 * fixes the bug without touching either contract.
 *
 * Two commands already work around this by hand -- AttributeOrphanPayments
 * checks `instanceof DateTimeInterface`, GenerateVehicleSummaries re-parses with
 * `Carbon::parse` -- which is how the column got away with being uncast for this
 * long. This is that same defence, in one place.
 */
final class TransDate
{
    /** ISO-8601, for JSON payloads. Null stays null. */
    public static function iso(mixed $value): ?string
    {
        return self::parse($value)?->toIso8601String();
    }

    /** `Y-m-d H:i:s`, for CSV columns and anything human-facing. */
    public static function dateTime(mixed $value): ?string
    {
        return self::parse($value)?->toDateTimeString();
    }

    /**
     * A Carbon, or null when there is nothing usable.
     *
     * Never throws: a malformed stored value must not turn a list of payments
     * into a 500. An unparseable date reads as absent, which is what the callers
     * were already (accidentally) rendering.
     */
    public static function parse(mixed $value): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value);
        }

        try {
            return Carbon::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }
}
