<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Pagination metadata for the hand-rolled `skip($offset)->take(20)` listings.
 *
 * Most list endpoints in this codebase page by slicing the query and returning
 * a bare array. That works, but the client is told nothing: no total, no page
 * count, no way to know whether another page exists. A table can render a
 * "next" button that leads nowhere, or hide one that should be there — and
 * nothing can show "showing 20 of 883".
 *
 * Rewriting them onto Laravel's paginator would change the response shape and
 * break every existing caller. This adds the metadata alongside the untouched
 * payload key instead, so old clients keep working and new ones can page
 * honestly.
 *
 * NOTE: take the meta from the query BEFORE skip/take is applied — otherwise
 * the count reflects the page, not the result set.
 */
trait PaginatesResults
{
    /** How long a total stays good enough. See the note on caching below. */
    private const COUNT_TTL_SECONDS = 60;

    /**
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     * @param  int  $countTtl  Seconds to cache the total for. 0 counts live.
     * @return array{total:int, per_page:int, current_page:int, last_page:int}
     */
    protected function pageMeta($query, Request $request, int $perPage = 20, ?int $countTtl = null): array
    {
        $total = $this->totalFor($query, $countTtl ?? self::COUNT_TTL_SECONDS);
        $current = max((int) $request->input('page', 1), 1);

        return [
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $current,
            'last_page' => (int) max((int) ceil($total / max($perPage, 1)), 1),
        ];
    }

    /**
     * The row count, cached briefly.
     *
     * WHY THIS IS CACHED AND THE PAGE ITSELF IS NOT. On the M-Pesa listing,
     * measured against production, the two halves of one request cost wildly
     * different amounts:
     *
     *     fetching the 20 rows      3.8 ms
     *     COUNT(*) for "page 1 of N"  437 ms
     *
     * The rows are cheap because LIMIT 20 lets PostgreSQL walk the TransTime
     * index backwards and stop. The count has no LIMIT to stop at, so the
     * planner switches strategy entirely and sequentially scans all 1.38M
     * transactions to resolve the tenant EXISTS, spilling ~56MB to temp. That
     * is 99% of the response, and it is spent on a number in the corner of the
     * screen.
     *
     * And it was being paid AGAIN on every page turn. Paging 1→2→3 through one
     * day of payments re-ran the same unchanged count three times. Caching it
     * makes the second and subsequent pages effectively free, which is exactly
     * where the complaint came from.
     *
     * THE KEY is the query's own SQL plus its bindings, so it is impossible for
     * two different result sets to share an entry — the tenant predicate that
     * SaccoScope adds is part of the SQL, and its sacco_id is part of the
     * bindings. Brand is safe twice over: BrandScope contributes its own
     * binding, and BrandContext gives each brand its own cache prefix.
     *
     * THE STALENESS is bounded and deliberately one-sided: the ROWS are always
     * read live, so nobody ever sees an out-of-date payment. Only the total can
     * lag, by at most a minute, and only while new rows are landing — which in
     * practice means today's date and no other. A count that is briefly one
     * payment behind is a much smaller problem than a page that takes half a
     * second to open.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     */
    private function totalFor($query, int $ttl): int
    {
        // Clone: the caller goes on to use $query for the actual page.
        $counter = clone $query;

        if ($ttl < 1) {
            return $counter->count();
        }

        try {
            $key = 'pagemeta:count:'.sha1($counter->toSql().'|'.$this->bindingFingerprint($counter));
        } catch (\Throwable $e) {
            // A query whose SQL cannot be rendered is not one to cache. Count it.
            return $counter->count();
        }

        return (int) Cache::remember($key, $ttl, static fn (): int => $counter->count());
    }

    /**
     * Bindings as one stable string.
     *
     * Dates arrive here as Carbon instances and objects as models, neither of
     * which stringify usefully on their own — two different days would produce
     * the same key and one SACCO's total would be served for another's page.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     */
    private function bindingFingerprint($query): string
    {
        return implode('|', array_map(static function ($binding): string {
            if ($binding instanceof \DateTimeInterface) {
                return $binding->format('Y-m-d H:i:s.u');
            }

            if (is_bool($binding)) {
                return $binding ? 'true' : 'false';
            }

            if ($binding === null) {
                return 'null';
            }

            if (is_scalar($binding)) {
                return (string) $binding;
            }

            // Anything else — an object, an array — is rendered structurally
            // rather than cast, so it cannot silently collapse to "Array".
            return md5(serialize($binding));
        }, $query->getBindings()));
    }

    /** Page size from the request, bounded so a client cannot ask for everything. */
    protected function perPage(Request $request, int $default = 20, int $max = 100): int
    {
        return min(max((int) $request->input('per_page', $default), 1), $max);
    }
}
