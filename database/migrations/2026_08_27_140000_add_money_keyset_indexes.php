<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Composite (timestamp, id) indexes for the two big money listings.
 *
 * Both screens sort newest-first over millions of rows — 1.33M `mpesas`
 * (491 MB), 1.31M `transactions` (310 MB). They previously ordered by the
 * timestamp alone, which is NOT unique: up to 10 prod rows share one
 * `TransTime`, and 1.33M rows hold only 990,721 distinct values. Tied rows come
 * back in whatever order the plan produces, so a row could appear on two pages
 * or on none.
 *
 * Adding `id` makes the sort total, and this index serves all three things the
 * listings now do in a single seek: the date-range filter, the ordering, and the
 * `(timestamp, id) < (?, ?)` cursor comparison.
 *
 * Ascending is deliberate — PostgreSQL scans a btree backwards at the same cost,
 * so one index covers newest-first and oldest-first (the export orders ASC).
 *
 * CONCURRENTLY, so building it does not lock out writes on a live table taking
 * ~2.5-3M KES/day. That requires running outside a transaction, hence
 * $withinTransaction = false. Follows 2026_08_07_120000_add_money_domain_indexes,
 * including its repair of a half-built invalid namesake.
 *
 * Leaves the existing single-column `mpesas_transtime_index` in place: it is now
 * redundant (same leading column) but dropping a live index belongs in its own
 * change, where it can be reverted on its own.
 */
return new class extends Migration
{
    /** Required by CREATE/DROP INDEX CONCURRENTLY. */
    public $withinTransaction = false;

    private const INDEXES = [
        // ORDER BY "TransTime" DESC, id DESC + WHERE "TransTime" >= ? AND < ?
        'mpesas' => ['mpesas_transtime_id_index' => ['TransTime', 'id']],

        // Same shape on the transactions ledger.
        'transactions' => ['transactions_trans_date_id_index' => ['trans_date', 'id']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                $this->addIndex($table, $name, $columns);
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                $this->dropIndexIfExists($table, $name);
            }
        }
    }

    private function addIndex(string $table, string $name, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            if (! Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
            }

            return;
        }

        $this->dropIfInvalid($name);

        DB::unprepared(sprintf(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (%s)',
            $this->quote($name),
            $this->quote($table),
            implode(', ', array_map($this->quote(...), $columns)),
        ));
    }

    /**
     * A CONCURRENTLY build that failed leaves an INVALID index: never used by
     * the planner, still maintained on every insert. `IF NOT EXISTS` matches on
     * name and would skip right over it, so drop it first and genuinely repair.
     */
    private function dropIfInvalid(string $name): void
    {
        $invalid = DB::selectOne(
            'SELECT 1 FROM pg_class c JOIN pg_index i ON i.indexrelid = c.oid
             WHERE c.relname = ? AND i.indisvalid = false',
            [$name]
        );

        if ($invalid !== null) {
            DB::unprepared('DROP INDEX CONCURRENTLY IF EXISTS '.$this->quote($name));
        }
    }

    private function dropIndexIfExists(string $table, string $name): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP INDEX CONCURRENTLY IF EXISTS '.$this->quote($name));

            return;
        }

        if (Schema::hasIndex($table, $name)) {
            Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
        }
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
};
