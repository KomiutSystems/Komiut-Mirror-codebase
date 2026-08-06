<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brand ownership within the single shared database.
 *
 * Passengers are global across brands. Operational data belongs to a brand
 * through the vehicle: `brand` on vehicles is the AUTHORITATIVE value, and it is
 * what queues, bookings, transactions and summaries scope through, so brand
 * filtering never needs a deep join.
 *
 * `brand` on saccos records the SACCO's primary brand — useful for listing and
 * ownership, but NOT a source to copy down onto its fleet. A SACCO may span
 * brands: in production NICCO MOVERS LIMITED runs 54 Co-op-financed vehicles
 * alongside 126 NCBA ones. Re-deriving vehicle brand from its SACCO would move
 * those 54 buses into the wrong app, and because BrandScope simply filters them
 * out, the mistake would be invisible rather than loud.
 *
 * NOTE: existing rows get NULL and need a one-off backfill to their real brand —
 * `brand:backfill` in SACCO mode for the bulk, then plate mode for the minority
 * that diverge. `brand:audit` reports anything left unbranded or split.
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
