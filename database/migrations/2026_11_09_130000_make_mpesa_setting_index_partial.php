<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two thirds of `mpesas_setting_transtime_index` indexes nothing anybody asks for.
 *
 * `mpesa_setting_id` is the id of the confirmation URL Safaricom posted a
 * payment to (see PaymentConnectionMap). It is NULL on 5.48M of the table's
 * 8.54M rows -- everything that arrived before the column existed, plus every
 * payment pulled rather than pushed. A btree indexes NULLs like any other
 * value, so 64% of a 259 MB index is entries no query can ever land on.
 *
 * Making it partial drops those entries. Every reader still uses it:
 *
 *   ->where('mpesa_setting_id', $id)   equality PROVES the column is not null,
 *                                      so Postgres matches the predicate itself
 *   ->whereNotNull('mpesa_setting_id') matches the predicate literally
 *
 * and those are the only two shapes in the codebase -- all five call sites in
 * PaymentConnectionMap, PullMpesaTransactions and AssignMpesaConnectionOwners.
 * There is no `IS NULL` reader and no unqualified scan of the column, which is
 * the one thing that would fall off a partial index. Checked before writing
 * this, because it is the whole risk.
 *
 * ~166 MB back on top of the 372 MB from 2026_11_09_120000. That matters here
 * for cache, not disk: the working set is 8.4 GB against 2 GB of RAM on
 * db.t4g.small, measured at a 98.93% buffer hit ratio whose misses land
 * squarely on the big scans. Every megabyte of dead index is a megabyte of
 * live data that cannot stay resident.
 */
return new class extends Migration
{
    /** CREATE/DROP INDEX CONCURRENTLY cannot run inside a transaction. */
    public $withinTransaction = false;

    private const FULL = 'mpesas_setting_transtime_index';

    private const PARTIAL = 'mpesas_setting_transtime_live_index';

    public function up(): void
    {
        if (! Schema::hasTable('mpesas')) {
            return;
        }

        // A CONCURRENTLY build that failed before leaves an INVALID index
        // behind, which is never used but is still maintained on every insert.
        $this->dropIfInvalid(self::PARTIAL);

        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::PARTIAL.
            ' ON mpesas USING btree (mpesa_setting_id, "TransTime")'.
            ' WHERE mpesa_setting_id IS NOT NULL'
        );

        // BUILD FIRST, DROP SECOND, and only if the new one came out valid.
        // CREATE INDEX CONCURRENTLY reports success for an index it could not
        // finish; dropping the old one on that would leave the column with
        // nothing but a 259 MB-shaped hole and send PaymentConnectionMap to a
        // sequential read of 8.54M rows on the live payment path.
        if ($this->isInvalid(self::PARTIAL)) {
            throw new RuntimeException(
                self::PARTIAL.' was built INVALID; keeping '.self::FULL.'. Drop the invalid index and re-run.'
            );
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::FULL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('mpesas')) {
            return;
        }

        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::FULL.
            ' ON mpesas USING btree (mpesa_setting_id, "TransTime")'
        );

        if ($this->isInvalid(self::FULL)) {
            throw new RuntimeException(self::FULL.' was rebuilt INVALID; keeping '.self::PARTIAL.'.');
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::PARTIAL);
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

    private function dropIfInvalid(string $name): void
    {
        if ($this->isInvalid($name)) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$name);
        }
    }
};
