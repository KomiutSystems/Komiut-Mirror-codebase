<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let the loyalty ledger be idempotent for fares that are not bookings.
 *
 * The ledger's only idempotency key was `unique(booking_id, type)`, which is
 * the whole reason earning could never cover the rails that carry the money.
 * A till confirmation and a QR fare create no Booking at all — C2B is ~98.6% of
 * revenue and touches nothing but mpesas/transactions/summaries — so there was
 * no column to key a credit on and no way to stop a replay double-crediting.
 *
 * A generic (source_type, source_id) pair fixes that once rather than adding a
 * nullable FK per payment rail. `booking_id` stays exactly as it is: the rows
 * already written keep their meaning, the existing unique index keeps guarding
 * the booking path, and nothing needs backfilling.
 *
 * NULLs ARE DISTINCT in a Postgres unique index, so every existing row — all of
 * which have source_type NULL — coexists happily, the same way NULL booking_id
 * rows (manual adjustments) already do under the older index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_transactions', function (Blueprint $table) {
            // e.g. 'mpesa' (a till/C2B confirmation) or 'qrcode_payment'.
            // Deliberately NOT a polymorphic relation: nothing loads the source
            // back through Eloquent, and a morph would invite a scoped lookup
            // into a webhook path that has no auth context.
            $table->string('source_type', 40)->nullable()->after('booking_id');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');

            $table->unique(['source_type', 'source_id', 'type'], 'loyalty_transactions_source_unique');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_transactions', function (Blueprint $table) {
            $table->dropUnique('loyalty_transactions_source_unique');
            $table->dropColumn(['source_type', 'source_id']);
        });
    }
};
