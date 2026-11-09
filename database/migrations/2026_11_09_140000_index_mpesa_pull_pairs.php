<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The one query on `mpesas` that still read the whole table.
 *
 * PullMpesaTransactions::recentPairs() asks "each (till, app) pair with
 * customer payments since X, most-paid first". `payments:pull` runs hourly and
 * the 14-day answer is cached for 6 hours, so this ran as a PARALLEL SEQ SCAN
 * four times a day: 11.7s of wall clock and 32.7s of read I/O across workers,
 * over 617,309 rows.
 *
 * The wall clock is not the problem -- nothing waits on a console command. The
 * problem is that sweeping a 1,638 MB heap four times a day evicts the buffer
 * cache on a 2 GB instance, so every other query pays for it afterwards. See
 * 2026_11_09_120000 for the same argument from the other direction.
 *
 * This index is PARTIAL ON BOTH FILTERS, which is what makes it small enough
 * to be worth having. `mpesa_setting_id IS NOT NULL` excludes 5.48M rows; the
 * TransactionType predicate excludes the 32,732 settlement transfers. Carrying
 * TransactionType as a stored column instead would have cost 77 MB for a
 * filter that removes 1% of the rows -- as a predicate it costs nothing, and
 * Postgres still proves the query's identical expression implies it.
 *
 * Measured on production, 2026-11-09:
 *
 *   14-day rebuild   11,745 ms  Parallel Seq Scan   ->  312 ms  Index Only Scan
 *   hourly refresh       72 ms  Bitmap Index Scan   ->   26 ms  Index Only Scan
 *
 * 117 MB, against the 541 MB the two migrations before this one gave back.
 *
 * THE PREDICATE MUST TRACK THE QUERY. If recentPairs() ever changes its
 * TransactionType filter, Postgres can no longer prove the implication and
 * silently falls back to the seq scan this exists to prevent. There is a note
 * on the query saying so.
 */
return new class extends Migration
{
    /** CREATE/DROP INDEX CONCURRENTLY cannot run inside a transaction. */
    public $withinTransaction = false;

    private const NAME = 'mpesas_pull_pairs_index';

    public function up(): void
    {
        if (! Schema::hasTable('mpesas')) {
            return;
        }

        // A CONCURRENTLY build that failed before leaves an INVALID index that
        // is never read but is still maintained on every insert.
        if ($this->isInvalid(self::NAME)) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::NAME);
        }

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::NAME.'
            ON mpesas USING btree ("TransTime", "BusinessShortCode", mpesa_setting_id)
            WHERE mpesa_setting_id IS NOT NULL
              AND coalesce("TransactionType", \'\') NOT LIKE \'Organization%\'');

        if ($this->isInvalid(self::NAME)) {
            throw new RuntimeException(
                self::NAME.' was built INVALID. Drop it and re-run; the pull job falls back to a seq scan until then.'
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('mpesas')) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::NAME);
        }
    }

    private function isInvalid(string $name): bool
    {
        $rows = DB::select(
            'select i.indisvalid from pg_index i join pg_class c on c.oid = i.indexrelid
             join pg_namespace n on n.oid = c.relnamespace
             where n.nspname = current_schema() and c.relname = ?',
            [$name]
        );

        return $rows !== [] && ! $rows[0]->indisvalid;
    }
};
