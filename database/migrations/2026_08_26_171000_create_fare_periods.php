<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Peak fares: a SACCO prices the same segment differently at different times.
 *
 * There was NO time-based fare support anywhere in this codebase — no column,
 * no config, no service. This is the whole of it.
 *
 * SHAPE. A period is defined once per SACCO ("Morning peak, Mon–Fri, 06:00 to
 * 09:00") and then priced against as many segments as the SACCO likes, rather
 * than every fare row carrying its own copy of the same window. A matatu SACCO
 * has two or three of these, not hundreds, and when the morning rush shifts they
 * want to move it in one place and not in four hundred.
 *
 * AMOUNTS, NOT MULTIPLIERS. Deliberate. Matatu fares are round negotiated
 * numbers — 100, 150, 200 — that a conductor shouts and a passenger argues
 * about. A 1.4x multiplier on a 150/= fare is 210/=, which nobody charges, and
 * the rounding rule would then become a second thing to get wrong. route_fares
 * already stores an amount; a peak fare is just another row with a period on it.
 *
 * OVERNIGHT WINDOWS. end_time may be EARLIER than start_time, meaning the window
 * wraps midnight (21:00 -> 05:00 is the late-night rate). FarePeriod::coversNow()
 * is the single place that understands this; nothing else should re-derive it.
 *
 * PRIORITY breaks overlaps deterministically. Two windows can legitimately cover
 * the same instant — a public-holiday rate over a weekday peak — and "whichever
 * row the planner returned first" is not an answer a SACCO can be given when a
 * passenger asks why they were charged what they were charged.
 *
 * TIMEZONE. Windows are Kenyan wall-clock (Africa/Nairobi, EAT, UTC+3) because
 * that is what "the 6am rush" means to everyone involved. Evaluation converts
 * explicitly — this system already has one EAT-vs-UTC trap in it, where
 * mpesas.TransTime is EAT wall-clock while created_at is UTC, and that one cost
 * a reconciliation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fare_periods', function (Blueprint $table): void {
            $table->id();

            // Owned outright. A period is a commercial decision — when this
            // SACCO thinks the rush is — and never shared.
            $table->unsignedBigInteger('sacco_id');

            $table->string('name', 60);

            // ISO-8601 day numbers, 1 = Monday .. 7 = Sunday, as a JSON array.
            // A bitmask would be denser and completely opaque in psql; there
            // will be a handful of these rows per SACCO, so legibility wins.
            $table->json('days');

            $table->time('start_time');
            $table->time('end_time');

            // Higher wins when two periods cover the same instant.
            $table->unsignedSmallInteger('priority')->default(0);

            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->index(['sacco_id', 'status'], 'fare_periods_sacco_status_index');
        });

        // A period belongs to at most one row per priced segment. NULL means
        // "the base fare", the price outside every window.
        Schema::table('route_fares', function (Blueprint $table): void {
            $table->unsignedBigInteger('fare_period_id')->nullable()->after('to_place_id');
        });

        // The old constraint assumed one fare per segment. Now it is one BASE
        // fare per segment plus one fare per (segment, period).
        //
        // Two PARTIAL unique indexes rather than one composite with a nullable
        // column: PostgreSQL treats NULLs as DISTINCT in a unique index, so a
        // plain UNIQUE(..., fare_period_id) would happily accept the same base
        // fare twice — which is exactly the row FareResolver would then have to
        // choose between arbitrarily. (PG15's NULLS NOT DISTINCT would also do
        // it; partial indexes say what they mean and do not need the version.)
        //
        // DROP CONSTRAINT before DROP INDEX. The create migration declared this
        // with $table->unique(...), which makes it a CONSTRAINT backed by an
        // index — and PostgreSQL refuses to drop that index directly: "cannot
        // drop index route_fares_pair_unique because constraint
        // route_fares_pair_unique on table route_fares requires it". Both
        // statements are IF EXISTS, so this works whichever way it was made.
        DB::statement('ALTER TABLE route_fares DROP CONSTRAINT IF EXISTS route_fares_pair_unique');
        DB::statement('DROP INDEX IF EXISTS route_fares_pair_unique');

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX route_fares_base_pair_unique
                ON route_fares (sacco_id, route_id, from_place_id, to_place_id)
             WHERE fare_period_id IS NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX route_fares_period_pair_unique
                ON route_fares (sacco_id, route_id, from_place_id, to_place_id, fare_period_id)
             WHERE fare_period_id IS NOT NULL
        SQL);

        // The resolver loads every fare for one (sacco, route) in a single read.
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS route_fares_sacco_route_index
                ON route_fares (sacco_id, route_id)
        SQL);
    }

    public function down(): void
    {
        foreach (['route_fares_base_pair_unique', 'route_fares_period_pair_unique',
            'route_fares_sacco_route_index'] as $name) {
            DB::statement("ALTER TABLE route_fares DROP CONSTRAINT IF EXISTS {$name}");
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }

        if (Schema::hasColumn('route_fares', 'fare_period_id')) {
            Schema::table('route_fares', function (Blueprint $table): void {
                $table->dropColumn('fare_period_id');
            });
        }

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS route_fares_pair_unique
                ON route_fares (sacco_id, route_id, from_place_id, to_place_id)
        SQL);

        Schema::dropIfExists('fare_periods');
    }
};
