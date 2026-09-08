<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a carbon credit be earned from a fare that has no booking.
 *
 * Carbon credits accrued from exactly one trigger: BookingPaid. That is the same
 * gap the loyalty scheme had — a QR fare writes a QrcodePayment and never a
 * Booking, so scanning the sticker on the bus, which is an IN-APP payment and
 * precisely the behaviour the scheme exists to reward, earned nothing at all.
 *
 * The ledger could not express the credit either: its only idempotency key is
 * the partial unique index on (booking_id) WHERE type = 'earned', and a QR fare
 * has no booking_id to key on. So a (source_type, source_id) pair, mirroring
 * what loyalty_transactions already carries, with its own partial unique index
 * over earn rows only — a redemption or a refund carries no source and several
 * of them must coexist per passenger.
 *
 * booking_id and its index are untouched: rows already written keep their
 * meaning and nothing needs backfilling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carbon_credit_transactions', function (Blueprint $table) {
            // e.g. 'qrcode_payment'. Not polymorphic: nothing loads the source
            // back through Eloquent, and a morph would invite a scoped lookup
            // into a webhook path that has no authenticated user.
            $table->string('source_type', 40)->nullable()->after('booking_id');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
        });

        // Partial, for the same reason the booking index is partial: only EARN
        // rows are one-per-source. NULLs are distinct in a Postgres unique index
        // anyway, but being explicit keeps redemptions and refunds — which carry
        // no source at all — provably outside it.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(
                "CREATE UNIQUE INDEX carbon_credit_transactions_earned_source_unique
                 ON carbon_credit_transactions (source_type, source_id)
                 WHERE type = 'earned' AND source_type IS NOT NULL"
            );
        } else {
            Schema::table('carbon_credit_transactions', function (Blueprint $table) {
                $table->unique(['source_type', 'source_id', 'type'], 'carbon_credit_transactions_earned_source_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement('DROP INDEX IF EXISTS carbon_credit_transactions_earned_source_unique');
        } else {
            Schema::table('carbon_credit_transactions', function (Blueprint $table) {
                $table->dropUnique('carbon_credit_transactions_earned_source_unique');
            });
        }

        Schema::table('carbon_credit_transactions', function (Blueprint $table) {
            $table->dropColumn(['source_type', 'source_id']);
        });
    }
};
