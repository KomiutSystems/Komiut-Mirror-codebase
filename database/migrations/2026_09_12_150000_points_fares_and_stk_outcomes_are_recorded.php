<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things a screen needs that were never written down.
 *
 * qrcode_payments.fare -- the KES a points-paid ride was worth. `amount` on a
 * points receipt is 0, honestly: no shilling reached the till. But the crew's
 * takings row and the passenger's receipt both need to say "KES 70 · 23.3
 * pts", and the only place the 70 lived was the request that has since been
 * answered. Nullable: an M-Pesa QR receipt keeps `amount`, and rows from
 * before this have no fare to recover.
 *
 * mpesa_stk_callbacks.result_code / result_desc -- what Safaricom actually
 * said. A failed push was recorded as nothing but `processed_at`, so the
 * status poll could only say "failed", and the passenger could not be told
 * the difference between "you cancelled" (1032), "no PIN in time" (1037) and
 * "insufficient funds" (1). Daraja puts the code in every callback; it is
 * kept now, success included.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qrcode_payments', function (Blueprint $table) {
            $table->double('fare')->nullable()->after('amount');
        });

        Schema::table('mpesa_stk_callbacks', function (Blueprint $table) {
            $table->integer('result_code')->nullable()->after('processed_at');
            $table->string('result_desc', 255)->nullable()->after('result_code');
        });
    }

    public function down(): void
    {
        Schema::table('qrcode_payments', function (Blueprint $table) {
            $table->dropColumn('fare');
        });

        Schema::table('mpesa_stk_callbacks', function (Blueprint $table) {
            $table->dropColumn(['result_code', 'result_desc']);
        });
    }
};
