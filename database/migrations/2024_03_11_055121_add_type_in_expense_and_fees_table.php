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
        Schema::table('expense_fees', function (Blueprint $table) {
            $table->enum('type', ["Expense", "Fees"])->default("Expense")->after("sacco_id");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expense_fees', function (Blueprint $table) {
            $table->dropColumn("type");
        });
    }
};
