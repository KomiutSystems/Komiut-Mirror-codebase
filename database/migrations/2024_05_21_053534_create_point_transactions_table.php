<?php

use App\Models\MpesaBookingCallback;
use App\Models\MpesaQrcodePayment;
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
        Schema::create('point_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(MpesaQrcodePayment::class)->nullable();
            $table->foreignIdFor(MpesaBookingCallback::class)->nullable();
            $table->unsignedDouble("points");
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('point_transactions');
    }
};
