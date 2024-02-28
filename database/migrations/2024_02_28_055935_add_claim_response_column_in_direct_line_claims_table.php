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
        Schema::table('direct_line_claims', function (Blueprint $table) {
            $table->text("claim_response")->nullable()->after("status");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('direct_line_claims', function (Blueprint $table) {
            $table->dropColumn("claim_response");
        });
    }
};
