<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Keyset ("seek") paging for the big money listings, newest first.
 *
 * OFFSET makes the database walk and discard every row it skips, so page 700
 * costs 700 pages of work. On one day of prod `mpesas` that is 18ms at page 1
 * and 81ms at offset 14,000; over a month-wide range the offset is in the
 * hundreds of thousands and the page stops returning.
 *
 * A cursor asks "the 20 rows after this exact one" instead, which is one index
 * seek whatever the depth.
 *
 * The tiebreaker is not optional. Up to 10 prod rows share a `TransTime`, and
 * 1.33M rows hold only 990,721 distinct ones — so ordering by the timestamp
 * alone leaves tied rows in whatever order the plan happens to produce, and a
 * row can appear on two pages or on none. `(timestamp, id)` is unique and
 * totally ordered, which is what makes both the cursor and plain OFFSET honest.
 *
 * Offset paging still works, so existing callers are unaffected.
 */
trait SeeksByCursor
{
    /**
     * Apply the cursor, if the caller sent one.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $column  Qualified sort column, e.g. `transactions.trans_date`.
     * @param  string  $key  Qualified unique tiebreaker, e.g. `transactions.id`.
     */
    protected function applyCursor($query, Request $request, string $column, string $key)
    {
        $cursor = $this->decodeCursor((string) $request->input('cursor', ''));

        if ($cursor === null) {
            return $query;
        }

        [$at, $id] = $cursor;
        $grammar = $query->getQuery()->getGrammar();

        // Row-value comparison: strictly "before" in the same total order the
        // ORDER BY uses. Written as one predicate so PostgreSQL can drive it
        // straight off the (column, key) index.
        return $query->whereRaw(
            '('.$grammar->wrap($column).', '.$grammar->wrap($key).') < (?, ?)',
            [$at, $id]
        );
    }

    /** Newest first, with the tiebreaker that makes the order total. */
    protected function orderForCursor($query, string $column, string $key)
    {
        return $query->orderBy($column, 'DESC')->orderBy($key, 'DESC');
    }

    /** The cursor a client sends back to get the next page, or null on the last. */
    protected function nextCursor($rows, string $attribute, string $keyAttribute, int $perPage): ?string
    {
        if (count($rows) < $perPage) {
            return null;
        }

        $last = $rows[count($rows) - 1];
        $at = $last->{$attribute};

        return base64_encode(($at instanceof \DateTimeInterface ? $at->format('Y-m-d H:i:s') : (string) $at)
            .'|'.$last->{$keyAttribute});
    }

    /** @return array{0:string, 1:int}|null */
    private function decodeCursor(string $cursor): ?array
    {
        if ($cursor === '') {
            return null;
        }

        $parts = explode('|', (string) base64_decode($cursor, true), 2);

        // A malformed cursor means page 1, not an error: it is usually a stale
        // link, and refusing the request loses the screen for no benefit.
        if (count($parts) !== 2 || $parts[0] === '' || ! ctype_digit($parts[1])) {
            return null;
        }

        return [$parts[0], (int) $parts[1]];
    }
}
