<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * When a bus's till_registration_status last changed (TillRegistrar::record).
 * till_registration_attempted_at only moves when Safaricom was asked, so it
 * cannot say when a bus became needs_head_office or no_keys.
 *
 * The ops board's hourly sweep needs it: a card a person moved to Done is
 * left alone while the problem has been there all along, and raised again
 * when the problem started after that (see AutoCardSource::raise). Rows from
 * before this column stay null, which the board reads as "unknown" and so
 * leaves a person's Done standing.
 *
 * A nullable column with no default is a catalogue change in PostgreSQL, no
 * table rewrite; lock_timeout stops the brief exclusive lock waiting behind a
 * long transaction with every vehicles write queued behind it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::table('vehicles', function (Blueprint $table) {
            $table->timestamp('till_registration_status_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('till_registration_status_at');
        });
    }
};
