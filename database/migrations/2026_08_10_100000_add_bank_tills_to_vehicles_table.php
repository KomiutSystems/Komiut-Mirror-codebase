<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bank's till, alongside the Daraja one.
 *
 * `till_number` / `merchant_short_code` are the SAFARICOM identifiers — what
 * Daraja quotes on a C2B confirmation. They are not what a bank reconciles
 * against: NCBA and Co-op each issue their own collection account per vehicle,
 * and neither was recorded anywhere, so "which of your tills is ours" could
 * only be answered by hand.
 *
 * Two columns rather than one, because a vehicle financed by NCBA can still be
 * moved to Co-op, and during a switch both are live. `financier` says which one
 * is CURRENT; these say what the numbers are.
 *
 * Nullable throughout: 474 vehicles have no till of any kind, and a default
 * would assert an account nobody opened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            if (! Schema::hasColumn('vehicles', 'ncba_till')) {
                $table->string('ncba_till')->nullable()->after('merchant_short_code');
            }
            if (! Schema::hasColumn('vehicles', 'coop_till')) {
                $table->string('coop_till')->nullable()->after('ncba_till');
            }
        });

        // Reconciliation reads are "find the vehicle for this till number", one
        // bank at a time, so each gets its own index rather than a composite.
        Schema::table('vehicles', function (Blueprint $table) {
            $table->index('ncba_till', 'vehicles_ncba_till_index');
            $table->index('coop_till', 'vehicles_coop_till_index');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropIndex('vehicles_ncba_till_index');
            $table->dropIndex('vehicles_coop_till_index');
            $table->dropColumn(['ncba_till', 'coop_till']);
        });
    }
};
