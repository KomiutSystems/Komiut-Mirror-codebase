<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remember which rail a booking chose to pay on (mpesa / ncba_till / coop_till
 * / wallet / loyalty_points). Nullable so existing rows and "book now, choose
 * later" both stay valid. Values are the backing strings of App\Enums\PaymentMethod.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('payment_method')->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }
};
