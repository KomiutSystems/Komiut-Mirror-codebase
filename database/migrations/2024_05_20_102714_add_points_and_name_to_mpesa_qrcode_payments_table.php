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
        Schema::table('mpesa_qrcode_payments', function (Blueprint $table) {
            $table->double("points")->unsigned()->default(0)->after('amount');
            $table->string("name")->nullable()->after('transid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mpesa_qrcode_payments', function (Blueprint $table) {
            $table->dropColumn("points");
            $table->dropColumn("name");
        });
    }
};
