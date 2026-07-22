<?php

use App\Models\QrcodePayment;
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
        Schema::create('mpesa_qrcode_payments', function (Blueprint $table) {
            $table->id();
            $table->string("transid")->unique();
            $table->double("amount")->unsigned();
            $table->string("phone");
            $table->datetime("transdate");
            $table->foreignIdFor(QrcodePayment::class);
            $table->text("callback");
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mpesa_qrcode_payments');
    }
};
