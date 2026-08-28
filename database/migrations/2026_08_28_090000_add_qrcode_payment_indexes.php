<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Index the QR payments screen.
 *
 * `qrcode_payments` has exactly one index: its primary key. The screen that
 * reads it filters on `created_at` for a day, narrows by vehicle, and — for a
 * passenger looking at their own receipts — by `user_id`. None of those has an
 * index to use, so every one of them reads the whole table.
 *
 * IT COSTS NOTHING TODAY, which is the entire reason to do it now: the table
 * holds zero rows because no QR payment has ever been made on this platform. It
 * will grow one row per scan, and QR is a per-passenger, per-boarding event — so
 * it grows with ridership, faster than anything keyed to fleet size. The first
 * time anyone notices the missing index it will be under load with real
 * passengers waiting.
 *
 * mpesa_qrcode_payments already has what it needs: a UNIQUE index on transid,
 * which is both the deduplication key and the join back to `mpesas`.
 *
 * CONCURRENTLY with $withinTransaction = false, matching every other index
 * migration here. A plain CREATE INDEX takes ACCESS EXCLUSIVE for the whole
 * build and blocks writes — on this system an index build has taken the legacy
 * stack down once. An empty table builds instantly either way; the point is
 * that a re-run against a full one stays safe.
 *
 * The INVALID sweep is because CONCURRENTLY has a failure mode plain builds do
 * not: an interrupted build leaves the index present but with indisvalid=false,
 * where the planner refuses it — and IF NOT EXISTS matches on NAME, so a re-run
 * would skip it forever while every INSERT kept paying to maintain it.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    /** index name => [table, column list] */
    private const INDEXES = [
        // The screen's default view: one SACCO's payments for a day, newest
        // first. vehicle_id leads because the vehicle filter is an equality and
        // the tenant scope reaches sacco through it.
        'qrcode_payments_vehicle_id_created_at_index' => ['qrcode_payments', 'vehicle_id, created_at DESC'],

        // A passenger reading their own receipts. The listing narrows a
        // saccoless caller to user_id, and that is the whole predicate.
        'qrcode_payments_user_id_created_at_index' => ['qrcode_payments', 'user_id, created_at DESC'],
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
     * Only matches indexes already marked invalid, so it can never drop a
     * healthy one. It cannot tell "failed earlier" from "another session is
     * building this right now" — both read as indisvalid = false — so two copies
     * of this migration must not run at once.
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
