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
        Schema::table('sacco_termini', function (Blueprint $table) {
            $table->unsignedDouble("geofence_radius")->nullable()->after("user_id");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sacco_termini', function (Blueprint $table) {
            $table->dropColumn("geofence_radius");
        });
    }
};
