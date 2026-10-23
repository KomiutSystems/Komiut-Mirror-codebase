<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * After the 2026-10-23 incident (crew phones counting a bus's whole history).
 *
 * VACUUM OFTEN ENOUGH FOR INDEX-ONLY SCANS. The driver takings count is now an
 * index-only scan, which skips the heap only for pages the visibility map marks
 * all-visible -- and only VACUUM sets those bits. With the defaults an insert-
 * mostly table is vacuumed after 20% of its rows are new: transactions (7.8M
 * rows, ~41k a day) every ~38 days, mpesas every ~5 weeks. Until then every
 * new page is a heap fetch, and the fast plan quietly decays. Per table:
 *
 *   transactions  0.5% + 10k inserts (~49k): about daily. B-tree indexes
 *                 only, and an insert-only vacuum skips index cleanup.
 *   mpesas        2% + 10k (~166k): about every four days. Its trigram GIN
 *                 indexes are read in full by each vacuum, so not daily.
 *
 * Storage parameters only: no rewrite, and the lock (SHARE UPDATE EXCLUSIVE)
 * blocks neither reads nor writes.
 *
 * TWO LOOKUPS THAT WALK A WHOLE TABLE AS THE CREW APP GOES LIVE. The open-run
 * lookups (currentLiveRun/currentStageQueue and friends: latest queue of one
 * kind for one bus) walk queues backwards by id with no index starting at
 * vehicle_id; a passenger's booking history has no index starting at user_id.
 * Both tables are tiny today (49 queues, 17 bookings), so the builds are
 * instant; they are here before the tables grow, not after.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    private const INDEXES = [
        'queues_vehicle_kind_id_index' => 'queues (vehicle_id, kind, id)',
        'bookings_user_id_created_at_index' => 'bookings (user_id, created_at)',
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE transactions SET (autovacuum_vacuum_insert_scale_factor = 0.005, autovacuum_vacuum_insert_threshold = 10000)');
        DB::statement('ALTER TABLE mpesas SET (autovacuum_vacuum_insert_scale_factor = 0.02, autovacuum_vacuum_insert_threshold = 10000)');

        foreach (self::INDEXES as $name => $on) {
            $this->dropIfInvalid($name);
            DB::unprepared("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name} ON {$on}");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE transactions RESET (autovacuum_vacuum_insert_scale_factor, autovacuum_vacuum_insert_threshold)');
        DB::statement('ALTER TABLE mpesas RESET (autovacuum_vacuum_insert_scale_factor, autovacuum_vacuum_insert_threshold)');

        foreach (array_keys(self::INDEXES) as $name) {
            DB::unprepared("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
        }
    }

    /** A CONCURRENTLY build that was interrupted leaves an INVALID index that IF NOT EXISTS would keep. */
    private function dropIfInvalid(string $name): void
    {
        $invalid = DB::selectOne(
            'select 1 as x from pg_class c join pg_index i on i.indexrelid = c.oid where c.relname = ? and not i.indisvalid',
            [$name]
        );
        if ($invalid !== null) {
            DB::unprepared("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
        }
    }
};
