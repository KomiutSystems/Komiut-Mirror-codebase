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
        Schema::table('transactions', function (Blueprint $table) {
            $table->index("mpesa_id");
            $table->index("cash_id");
            $table->index("vehicle_id");
            $table->index("trans_date");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex("mpesa_id");
            $table->dropIndex("cash_id");
            $table->dropIndex("vehicle_id");
            $table->dropIndex("trans_date");
        });
    }
};
