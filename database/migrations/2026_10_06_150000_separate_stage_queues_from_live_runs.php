<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A place in a stage's line and a bus that has gone live on a route are two
 * different things, and from here they are two kinds of row.
 *
 * `queues` carried both. Going live on a route created an ACTIVE 'LIVE' row so
 * bookings had something to sit on (bookings.queue_id is NOT NULL, the tracker
 * channel is trip.{queue_id}), and a location ping attached itself to whatever
 * queue the bus had open -- a stage queue included -- so a bus waiting at
 * Ambassadeur that switched on its broadcast became bookable on the
 * Ambassadeur - Alsops route, whatever route the driver had chosen to go live
 * on. Joining a queue required a route as well, and the driver app derived one
 * from the stage tapped: on 2026-10-06 KDN 458N, having just finished
 * Nairobi CBD - Thika, was queued on Ambassadeur - Alsops that way.
 *
 * `kind`:
 *   stage  a place in a stage's FIFO line. Chosen by STAGE only, never
 *          bookable. route_id is NULL for rows created from here on.
 *   live   the bus running a route because the driver went live on it. The
 *          only thing passengers can find and book.
 *
 * route_id becomes nullable for the route-less stage rows, and those rows get
 * their own line numbering per (stage, day): the existing unique index is over
 * (terminus, route, day, position) and NULL route ids never collide there.
 * Older stage rows keep their route and their index untouched.
 */
return new class extends Migration
{
    private const STAGE_LINE_INDEX = 'queues_stage_terminus_day_position_unique';

    public function up(): void
    {
        if (! Schema::hasColumn('queues', 'kind')) {
            Schema::table('queues', function (Blueprint $table): void {
                $table->string('kind', 10)->default('stage')->after('queue_number');
            });
        }

        // The only rows that were ever live runs: LiveRun stamped them 'LIVE'.
        DB::table('queues')->where('queue_number', 'LIVE')->update(['kind' => 'live']);

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE queues ALTER COLUMN route_id DROP NOT NULL');

            // `queues` is a few hundred rows; a plain build takes no time.
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS '.self::STAGE_LINE_INDEX.
                ' ON queues (terminus_id, (created_at::date), position)'.
                " WHERE route_id IS NULL AND position IS NOT NULL AND kind = 'stage'"
            );
            DB::statement('CREATE INDEX IF NOT EXISTS queues_vehicle_kind_index ON queues (vehicle_id, kind)');
        } else {
            Schema::table('queues', function (Blueprint $table): void {
                $table->unsignedBigInteger('route_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS '.self::STAGE_LINE_INDEX);
            DB::statement('DROP INDEX IF EXISTS queues_vehicle_kind_index');
        }
        // route_id stays nullable on the way down: route-less stage rows may
        // exist by then, and forcing NOT NULL would fail or require deleting them.
        if (Schema::hasColumn('queues', 'kind')) {
            Schema::table('queues', function (Blueprint $table): void {
                $table->dropColumn('kind');
            });
        }
    }
};
