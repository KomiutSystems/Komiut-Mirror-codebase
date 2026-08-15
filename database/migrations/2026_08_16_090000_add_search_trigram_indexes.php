<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Trigram indexes for the dashboard search box.
 *
 * Every search in this codebase is a leading-wildcard match — `ILIKE '%needle%'`
 * — because a SACCO types a fragment of a receipt number or a passenger's name.
 * A btree index cannot serve that: it can seek a prefix, not an infix. So
 * `mpesas_transid_unique` and `mpesas_firstname_middlename_lastname_index`,
 * despite covering exactly the searched columns, are both unusable here and the
 * planner falls back to reading the whole date window and filtering per row.
 *
 * Measured on production before this migration, against the busiest day
 * (2026-08-07, 48,236 transactions), searching one fragment:
 *
 *     Bitmap Heap Scan over the day + 46,827 per-row lookups into mpesas
 *     Execution Time: 184 ms
 *
 * Survivable at 1.3M rows. It is linear in table size, though, and the legacy
 * MySQL stack — the same schema at 20.5M rows — is where that shape became a
 * multi-second query and eventually took the site down. This is the index that
 * stops the new stack arriving at the same place.
 *
 * ONLY `mpesas`. The other searched tables are small enough that a sequential
 * scan is cheaper than an index probe, and a GIN index on them would cost write
 * throughput for nothing:
 *
 *     mpesas    1,310,063 rows   <- indexed here
 *     users         6,805
 *     vehicles        894
 *     saccos           47
 *     cashes            0
 *
 * Postgres only. MySQL has no trigram index, and on MySQL these searches were
 * never case-broken in the first place (see App\Services\Sql\LikeSql).
 */
return new class extends Migration
{
    /**
     * CREATE INDEX CONCURRENTLY cannot run inside a transaction, and Laravel
     * wraps migrations in one by default. Without this the migration fails with
     * "CREATE INDEX CONCURRENTLY cannot run inside a transaction block".
     *
     * The trade-off is that a failure here leaves an INVALID index behind rather
     * than rolling back — `down()` drops it either way, and CONCURRENTLY is what
     * keeps the table writable while the index builds. On a table taking live
     * Daraja C2B webhooks, a build that locks out inserts is not acceptable.
     */
    public $withinTransaction = false;

    /** @var array<int, string> columns the search box actually matches against */
    private const COLUMNS = ['TransID', 'FirstName', 'MiddleName', 'LastName'];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Requires rds_superuser (or superuser). The app role is a member on
        // this cluster; IF NOT EXISTS keeps it a no-op when already installed.
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        foreach (self::COLUMNS as $column) {
            $index = 'mpesas_'.strtolower($column).'_trgm_index';

            DB::statement(sprintf(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON mpesas USING gin ("%s" gin_trgm_ops)',
                $index,
                $column
            ));
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::COLUMNS as $column) {
            $index = 'mpesas_'.strtolower($column).'_trgm_index';
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$index);
        }

        // The extension is deliberately left installed: other things may come to
        // depend on it, and dropping it would cascade to their indexes.
    }
};
