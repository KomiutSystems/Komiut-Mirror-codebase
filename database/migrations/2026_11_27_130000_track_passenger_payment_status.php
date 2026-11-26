<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two facts the passenger's Payments tab needs that nothing records yet.
 *
 * mpesa_stk_callbacks.unconfirmed_at -- when we stopped waiting. A push that
 * Safaricom (or the bank) never answers stayed "processing" forever: the
 * callback never came, the STK query could not say, and processed_at stayed
 * null. payments:reconcile now stamps this once the prompt is ten minutes
 * past and still undecided, and the passenger reads "failed". It is NOT
 * processed_at, on purpose: processed_at closes the nonce, and a callback
 * that arrives after we gave up and proves the money moved must still be
 * applied -- the push flips to paid, which is what the passenger is owed.
 *
 * bookings / qrcode_payments.payment_announced -- the last status the
 * passenger's phone was told about (payment.updated). Daraja resends a
 * callback, three pollers can settle the same push, and one settlement saves
 * three rows; each of those wants to announce. A conditional UPDATE on this
 * column is the one-winner test that survives two app hosts and two queue
 * workers: whoever moves it from the old value announces, everyone else
 * finds it already there. Kept on the row rather than in the cache because
 * the cache prefix follows the brand of whoever is running, and a callback
 * and a sweep do not run under the same one.
 *
 * Nullable, no default, no backfill: adding them is a catalogue change only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('mpesa_stk_callbacks', 'unconfirmed_at')) {
            Schema::table('mpesa_stk_callbacks', function (Blueprint $table) {
                $table->timestamp('unconfirmed_at')->nullable()->after('cancelled_at');
            });
        }

        foreach (['bookings', 'qrcode_payments'] as $name) {
            if (! Schema::hasColumn($name, 'payment_announced')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->string('payment_announced', 40)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        Schema::table('mpesa_stk_callbacks', function (Blueprint $table) {
            $table->dropColumn('unconfirmed_at');
        });

        foreach (['bookings', 'qrcode_payments'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('payment_announced');
            });
        }
    }
};
