<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which bank financed the vehicle — and therefore whose till its M-Pesa
 * collections settle to.
 *
 * The legacy system carries this on `vehicles.financier` (NCBA 829, coop-bank
 * 54). It was not migrated, so the new system holds all 883 vehicles and all
 * 409 tills but no way to tell a Co-op till from an NCBA one. That distinction
 * is not cosmetic: the two banks reconcile separately, and "which of your tills
 * are ours" is the first question either of them asks.
 *
 * Deliberately a plain nullable string rather than an enum or a foreign key:
 * the values come from a legacy free-text column, the list of financiers is a
 * business fact that changes without a deploy, and 474 vehicles have no till at
 * all so a NOT NULL default would assert a bank that nobody chose.
 *
 * `brand` is NOT a substitute. It says which portal a vehicle appears on
 * (komiut / 2safiri); financier says who banks it. They correlate today but are
 * not the same fact, and a SACCO can span both.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('vehicles', 'financier')) {
            return;
        }

        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('financier')->nullable()->after('merchant_short_code');
            // Every till screen filters by it, and the bank reconciliation reads
            // are "all vehicles for financier X that have a till".
            $table->index(['financier', 'sacco_id'], 'vehicles_financier_sacco_id_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('vehicles', 'financier')) {
            return;
        }

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropIndex('vehicles_financier_sacco_id_index');
            $table->dropColumn('financier');
        });
    }
};
