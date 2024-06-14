<?php

use App\Models\Vehicle;
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
        Schema::table('redeemed_points', function (Blueprint $table) {
            $table->foreignIdFor(Vehicle::class)->after('point_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('redeemed_points', function (Blueprint $table) {
            $table->dropColumn("vehicle_id");
        });
    }
};
