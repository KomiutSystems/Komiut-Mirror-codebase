<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How fast the bus has been moving over its last few fixes, so "your school
 * bus is about 2 minutes away" is an estimate from the bus itself rather than
 * a distance. Smoothed and bounded in VehicleLocationService::update. NULL
 * until two fixes a sensible time apart have been seen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_locations', function (Blueprint $table) {
            $table->decimal('speed_kmh', 5, 1)->nullable()->after('heading');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_locations', function (Blueprint $table) {
            $table->dropColumn('speed_kmh');
        });
    }
};
