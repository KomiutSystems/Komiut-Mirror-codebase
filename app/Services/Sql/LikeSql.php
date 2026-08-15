<?php

declare(strict_types=1);

namespace App\Services\Sql;

use Illuminate\Support\Facades\DB;

/**
 * The pattern-match operator for "search boxes", per SQL dialect.
 *
 * Every dashboard search in this codebase was written against MySQL, where the
 * LIKE operator is CASE-INSENSITIVE by default — the column collations are
 * `_ci`, so searching a plate matched `KCH 875N` and nobody had to think about
 * it.
 *
 * Postgres does not work that way. Its LIKE is case-SENSITIVE, always. So the
 * move to Postgres silently changed what every one of these searches returns:
 *
 *     'KCH 875N' LIKE  '%kch%'  ->  false
 *     'KCH 875N' ILIKE '%kch%'  ->  true
 *
 * There is no error and no warning. A SACCO typing a plate in lower case just
 * gets an empty result and concludes the vehicle has no transactions — the kind
 * of silent wrong answer that is worse than a crash. The legacy MySQL stack
 * still returns rows for the same search, so it reads as missing data rather
 * than a broken query.
 *
 * ILIKE is a strict superset: everything the case-sensitive operator matched, it
 * matches too. Safe wherever a human typed the pattern.
 *
 * NOT for matching where case is meaningful — receipt numbers, tokens or hashes
 * compared exactly should not be pattern matches at all.
 */
final class LikeSql
{
    /**
     * The case-insensitive pattern-match operator for the current connection.
     *
     * Postgres has ILIKE. MySQL/MariaDB reach the same behaviour through their
     * default `_ci` collation, and sqlite's is case-insensitive for ASCII, so
     * the plain operator is already correct on both.
     */
    public static function op(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
    }
}
