<?php

use App\Models\Booking;
use App\Models\QrcodePayment;
use App\Models\Vehicle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('mpesa_stk_callbacks', function (Blueprint $table) {
            $table->foreignIdFor(Booking::class)->nullable()->change();
            $table->foreignIdFor(QrcodePayment::class)->nullable()->after('booking_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mpesa_stk_callbacks', function (Blueprint $table) {
            $table->dropColumn("qrcode_payment_id");
            $table->foreignIdFor(Booking::class)->change();
        });
    }
};
