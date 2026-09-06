<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record when a matatu actually left the stage.
 *
 * `queues.start_time` was carrying three different meanings. Joining a queue set
 * it to now; scheduling one set it to the planned departure; and departing
 * OVERWROTE it with now again. So after departure there was no longer any record
 * of when the vehicle joined the line, and "how long did this bus wait at the
 * terminus" — the number a SACCO uses to judge whether a stage is over-served —
 * could not be answered at all.
 *
 * departed_at makes the lifecycle legible: start_time is when the queue began
 * (or was scheduled to), departed_at is when it actually pulled out, end_time is
 * when it arrived. Waiting time is departed_at - start_time; journey time is
 * end_time - departed_at.
 *
 * Nullable and backfilled to nothing on purpose. Existing rows genuinely do not
 * know when they departed — their start_time was already overwritten — and
 * inventing a value would put a fabricated waiting time of zero on every
 * historical trip. Null reads as "not recorded", which is the truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queues', function (Blueprint $table): void {
            $table->dateTime('departed_at')->nullable()->after('start_time');
        });
    }

    public function down(): void
    {
        Schema::table('queues', function (Blueprint $table): void {
            $table->dropColumn('departed_at');
        });
    }
};
