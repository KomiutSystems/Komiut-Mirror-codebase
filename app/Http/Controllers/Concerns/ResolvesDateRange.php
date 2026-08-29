<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * One definition of "which days is this screen asking about".
 *
 * Every money listing takes the same three parameters — `date` for a single
 * day, or `from`/`to` for a span — and every one of them has to agree about
 * where a day ends, or the same filter gives two different answers on two
 * screens and the totals stop reconciling.
 *
 * This was lifted out of SummariesAPIController, which had the only correct
 * implementation. Transactions had only `date`, so the dashboard assembled a
 * week by issuing ONE REQUEST PER DAY and merging in the browser — thirty round
 * trips for a month, capped at 31 days because it could not go further.
 *
 * HALF-OPEN, always: [from, to). `trans_date` is a timestamp, so an inclusive
 * BETWEEN counts the next day's 00:00:00 rows into both days — the classic way
 * two adjacent days each claim the same payment and the month total exceeds the
 * sum of its days.
 *
 * `date` is the ORIGINAL parameter and keeps working untouched. Callers may send
 * `date` and `from`/`to` together (the dashboard already does, so that honouring
 * the range needs no client change); the range wins when either end is present.
 */
trait ResolvesDateRange
{
    /**
     * @return array{0: Carbon, 1: Carbon} [from inclusive, to EXCLUSIVE]
     */
    protected function dateRange(Request $request): array
    {
        if (filled($request->from) || filled($request->to)) {
            // One end given is a single day, not an open-ended sweep of the
            // table: a missing `to` means "the day `from` names".
            $from = Carbon::parse($request->input('from', $request->input('to')))->startOfDay();
            $to = Carbon::parse($request->input('to', $request->input('from')))->startOfDay()->addDay();
        } else {
            $from = filled($request->date) ? Carbon::parse($request->date)->startOfDay() : Carbon::today();
            $to = $from->copy()->addDay();
        }

        // A reversed or equal pair would otherwise select nothing and read as
        // "no payments that week" rather than as the bad input it is.
        if ($to->lessThanOrEqualTo($from)) {
            $to = $from->copy()->addDay();
        }

        return [$from, $to];
    }

    /**
     * The applied range, echoed back so a client can tell a server that honoured
     * `from`/`to` from one that silently fell back to a single `date`. Without
     * it a month-wide filter can render one day's payments and read as the month.
     *
     * @return array{from:string, to:string} `to` is the last INCLUDED day.
     */
    protected function rangeMeta(Carbon $from, Carbon $to): array
    {
        return [
            'from' => $from->toDateString(),
            'to' => $to->copy()->subDay()->toDateString(),
        ];
    }

    /** The span as a filename-safe label, with `to` shown as the last INCLUDED day. */
    protected function dateRangeLabel(Carbon $from, Carbon $to): string
    {
        return $from->toDateString().'_to_'.$to->copy()->subDay()->toDateString();
    }
}
