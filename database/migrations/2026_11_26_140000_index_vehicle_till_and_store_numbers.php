<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The two numbers a bus is found by on the money path, unindexed until now:
 *   - till_number: a scanned or typed sticker (QrStickerResolver, qrcode/vehicle);
 *   - merchant_short_code: every M-Pesa / bank payment notification, matched to its bus.
 * 953 buses scan fast enough today; at 10k scans a day and a growing fleet every
 * one of those would be a sequential scan. The table is small, so building these
 * takes milliseconds; lock_timeout stops it waiting behind a long transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        Schema::table('vehicles', function (Blueprint $table) {
            $table->index('till_number');
            $table->index('merchant_short_code');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropIndex(['till_number']);
            $table->dropIndex(['merchant_short_code']);
        });
    }
};
