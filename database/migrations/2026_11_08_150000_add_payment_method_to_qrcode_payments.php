<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a QR fare was actually paid.
 *
 * `qrcode_payments` records a tap at the door, and until now every cashless one
 * looked the same: LoyaltyService writes `amount = 0` with the fare in `fare`,
 * on the honest grounds that a points ride puts no shillings in the till. A
 * wallet tap writes exactly the same shape for exactly the same reason -- the
 * money is in the Komiut float, not the SACCO's till -- which would leave the
 * receipt, and the conductor reading it, unable to tell a points ride from a
 * wallet one.
 *
 * Nullable, and null means what every existing row means: a points redemption
 * or a legacy import. Backfilling would be guessing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qrcode_payments', function (Blueprint $table) {
            $table->string('payment_method', 20)->nullable()->after('fare');
        });
    }

    public function down(): void
    {
        Schema::table('qrcode_payments', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }
};
