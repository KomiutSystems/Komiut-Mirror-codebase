<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

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
    /**
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     * @return array{total:int, per_page:int, current_page:int, last_page:int}
     */
    protected function pageMeta($query, Request $request, int $perPage = 20): array
    {
        // Clone: the caller goes on to use $query for the actual page.
        $total = (clone $query)->count();
        $current = max((int) $request->input('page', 1), 1);

        return [
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $current,
            'last_page' => (int) max((int) ceil($total / max($perPage, 1)), 1),
        ];
    }

    /** Page size from the request, bounded so a client cannot ask for everything. */
    protected function perPage(Request $request, int $default = 20, int $max = 100): int
    {
        return min(max((int) $request->input('per_page', $default), 1), $max);
    }
}
