<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brand ownership within the single shared database.
 *
 * Passengers are global across brands, but a SACCO and its fleet belong to one
 * brand (komiut vs 2safiri). `brand` on saccos is the authoritative owner;
 * it is denormalised onto vehicles (the operational hub through which queues,
 * bookings, transactions and summaries are scoped) so brand filtering doesn't
 * need a deep join on every query.
 *
 * NOTE: existing rows get NULL and need a one-off backfill to their real brand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saccos', function (Blueprint $table): void {
            $table->string('brand')->nullable()->index()->after('status');
        });

        Schema::table('vehicles', function (Blueprint $table): void {
            $table->string('brand')->nullable()->index()->after('sacco_id');
        });
    }

    public function down(): void
    {
        Schema::table('saccos', fn (Blueprint $table) => $table->dropColumn('brand'));
        Schema::table('vehicles', fn (Blueprint $table) => $table->dropColumn('brand'));
    }
};
