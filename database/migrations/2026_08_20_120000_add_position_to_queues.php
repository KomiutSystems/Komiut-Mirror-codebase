<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Integer FIFO `position` for queues + a per-slot uniqueness guarantee.
 *
 * `queue_number` is a display string ('QN-7') that the dispatch list ordered
 * LEXICALLY, so 'QN-10' sorted between 'QN-1' and 'QN-2' and FIFO broke the
 * moment a terminus+route saw more than nine vehicles in a day. This adds an
 * integer `position` that carries the true order, and a UNIQUE index so two
 * vehicles can never be handed the same slot for the same
 * (terminus, route, business-day) — the race the old `count()+1` left wide open.
 * `queue_number` is kept for display.
 */
return new class extends Migration
{
    /** Name of the functional slot-uniqueness index (pgsql only). */
    private const UNIQUE_INDEX = 'queues_terminus_route_day_position_unique';

    public function up(): void
    {
        if (! Schema::hasColumn('queues', 'position')) {
            Schema::table('queues', function (Blueprint $table): void {
                // Nullable: historical rows predate this column, and any legacy
                // insert path that never sets it must still succeed. New code
                // (DriverQueueController::join, QueuesAPIController::addQueue)
                // always assigns it.
                $table->integer('position')->nullable()->after('queue_number');
            });
        }

        $this->backfill();

        // The slot lock. Many queues share a (terminus, route, day) — that IS the
        // queue — but never the same POSITION within it. A functional index over
        // (created_at::date) scopes the guarantee to the business day with no
        // extra stored column; casting a `timestamp` to `date` is immutable, so
        // Postgres accepts it in an index expression. NULL positions (rows with a
        // non-numeric queue_number that could not be backfilled) are treated as
        // distinct by a btree unique index, so they never collide with each other.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS '.self::UNIQUE_INDEX.
                ' ON queues (terminus_id, route_id, (created_at::date), position)'
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS '.self::UNIQUE_INDEX);
        }

        if (Schema::hasColumn('queues', 'position')) {
            Schema::table('queues', function (Blueprint $table): void {
                $table->dropColumn('position');
            });
        }
    }

    /**
     * Seed `position` from the numeric suffix of the existing queue_number
     * ('QN-7' -> 7). Only rows still NULL are touched, so a re-run is a no-op.
     */
    private function backfill(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            // Strip every non-digit then cast; a queue_number with no digits at
            // all yields an empty string, which NULLIF turns back into NULL
            // rather than failing the cast and the whole migration.
            DB::statement(
                "UPDATE queues
                    SET position = NULLIF(regexp_replace(queue_number, '\\D', '', 'g'), '')::integer
                  WHERE position IS NULL"
            );

            // DEDUPE before the unique index. Legacy data may already hold the very
            // collision this migration exists to stop -- two vehicles with the same
            // 'QN-N' for one (terminus, route, day) -> two identical positions, which
            // would make CREATE UNIQUE INDEX below fail outright. So renumber every
            // constrained slot group to a gap-free FIFO sequence by created_at,
            // guaranteeing uniqueness. Rows with a NULL terminus/route are left as
            // backfilled (NULLs are distinct under a btree unique index, so they
            // can never collide and need no renumbering).
            DB::statement(
                'WITH ordered AS (
                    SELECT id, ROW_NUMBER() OVER (
                        PARTITION BY terminus_id, route_id, (created_at::date)
                        ORDER BY created_at, id
                    ) AS rn
                    FROM queues
                    WHERE terminus_id IS NOT NULL AND route_id IS NOT NULL
                )
                UPDATE queues q SET position = o.rn FROM ordered o WHERE q.id = o.id'
            );

            return;
        }

        // Portable fallback for sqlite/mysql dev boxes: parse in PHP, chunked so
        // a large table is not loaded at once. DB facade (not the Eloquent model)
        // so no global scope narrows the rows being backfilled.
        DB::table('queues')
            ->whereNull('position')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    if (preg_match('/\d+/', (string) $row->queue_number, $m) === 1) {
                        DB::table('queues')->where('id', $row->id)->update(['position' => (int) $m[0]]);
                    }
                }
            });
    }
};
