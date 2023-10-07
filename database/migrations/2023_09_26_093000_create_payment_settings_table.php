<?php

use App\Models\Sacco;
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
        Schema::create('mpesa_payment_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Sacco::class)->nullable();
            $table->string("consumer_key");
            $table->string('consumer_secret');
            $table->string("pass_key");
            $table->string("business_short_code");
            $table->enum("payment_mode", ["CustomerPayBillOnline", "CustomerBuyGoodsOnline"]);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mpesa_payment_settings');
    }
};
