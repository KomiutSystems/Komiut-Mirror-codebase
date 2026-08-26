<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Index the loyalty holder lists.
 *
 * WHAT WAS MISSING. `loyalty_accounts` has exactly two indexes: the primary key,
 * and UNIQUE (user_id, sacco_id). That composite serves a `user_id` lookup
 * because user_id leads it — but nothing serves `sacco_id` on its own. The
 * foreignIdFor()->constrained() in the create migration produced a FOREIGN KEY,
 * and PostgreSQL does not index the referencing side of a foreign key. So the
 * query the SACCO holder list is entirely built around —
 *
 *     WHERE sacco_id = ? AND balance > 0 ORDER BY balance DESC
 *
 * — has no index to use at all and reads the whole table.
 *
 * That costs nothing today, because the table is empty: loyalty is waiting on
 * bookings, and Frankfurt has none. It is being added NOW precisely because it
 * is free now. Points accrue one row per (passenger, SACCO) on every paid
 * booking, so this table grows with ridership rather than with fleet size, and
 * the first time anyone notices the missing index it will be under load with
 * real passengers waiting on the response.
 *
 * COLUMN ORDER. sacco_id leads because every holder query filters on it by
 * equality — the SACCO endpoint from the caller's own tenant, the super endpoint
 * from an optional filter. balance follows in DESC order so the "top holders"
 * sort is read straight off the index rather than sorted afterwards.
 *
 * CONCURRENTLY, and `$withinTransaction = false` to allow it. A plain
 * CREATE INDEX takes an ACCESS EXCLUSIVE lock for the whole build and blocks
 * every write to the table; on this system an index build has already taken the
 * legacy stack down once. The table being empty today makes the build
 * instantaneous either way — the point is that the migration is written so it
 * stays safe when someone re-runs it against a table that is no longer empty.
 *
 * The INVALID sweep exists because CONCURRENTLY has a failure mode plain builds
 * do not: a build that is interrupted leaves the index in the catalogue with
 * indisvalid = false, where pg_indexes still reports it as present but the
 * planner refuses to use it — and IF NOT EXISTS matches on NAME, so a re-run
 * would skip it forever while every INSERT kept paying to maintain it.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    /** index name => [table, column list] */
    private const INDEXES = [
        // The SACCO holder list, and the super list when filtered to one SACCO.
        'loyalty_accounts_sacco_id_balance_index' => ['loyalty_accounts', 'sacco_id, balance DESC'],

        // The super list groups by PERSON — a passenger may hold points with
        // several SACCOs — so it reads every account for a set of user ids. The
        // existing UNIQUE (user_id, sacco_id) already serves that as a prefix,
        // so there is deliberately no second index here: an unused index is not
        // free, it is a write cost on every earn.
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => [$table, $columns]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $this->dropIfInvalid($name);

            DB::unprepared(sprintf(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (%s)',
                $name,
                $table,
                $columns
            ));
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            DB::unprepared('DROP INDEX CONCURRENTLY IF EXISTS '.$name);
        }
    }

    /**
     * Clear the wreckage of a previously interrupted CONCURRENTLY build.
     *
     * Only matches indexes that are already marked invalid, so it can never drop
     * a healthy one. It does not distinguish "failed earlier" from "another
     * session is building this right now" — both read as indisvalid = false — so
     * two copies of this migration must not run at once.
     */
    private function dropIfInvalid(string $name): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $invalid = DB::selectOne(
            'select 1 from pg_class c
               join pg_index i on i.indexrelid = c.oid
              where c.relname = ? and i.indisvalid = false',
            [$name]
        );

        if ($invalid !== null) {
            DB::unprepared('DROP INDEX CONCURRENTLY IF EXISTS '.$name);
        }
    }
};
