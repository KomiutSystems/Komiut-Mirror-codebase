<?php

use App\Models\Booking;
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
        Schema::create('mpesa_booking_callbacks', function (Blueprint $table) {
            $table->id();
            $table->string("transid")->unique();
            $table->unsignedDouble("amount");
            $table->string("phone");
            $table->datetime("transdate");
            $table->foreignIdFor(Booking::class);
            $table->text("callback");
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mpesa_booking_callbacks');
    }
};
