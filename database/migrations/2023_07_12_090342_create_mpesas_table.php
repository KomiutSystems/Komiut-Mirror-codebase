<?php

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
        Schema::create('mpesas', function (Blueprint $table) {
            $table->id();
            $table->string('TransID')->unique();
            $table->string('MSISDN');
            $table->string('TransAmount');
            $table->datetime('TransTime');
            $table->string('FirstName')->nullable();
            $table->string('MiddleName')->nullable();
            $table->string('LastName')->nullable();
            $table->string('ThirdPartyTransID')->nullable();
            $table->string('InvoiceNumber')->nullable();
            $table->string('BillRefNumber')->nullable();
            $table->string('BusinessShortCode')->nullable();
            $table->string('TransactionType')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mpesas');
    }
};
