<?php

use App\Models\Place;
use App\Models\Route;
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
        Schema::create('route_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Route::class);
            $table->foreignIdFor(Place::class);
            $table->double("longitude")->nullable();
            $table->double("latitude")->nullable();
            // `distance` is the along-route ordinal: a stop's position from the
            // origin. Point-first search orders stops by it (pickup.distance <
            // dropoff.distance), so it must be set for every stage.
            $table->double("distance")->unsigned()->default(0);
            $table->boolean('status')->default(1);
            $table->timestamps();

            // Ordered stops per route (the point-first self-join reads these).
            $table->index(['route_id', 'distance']);
            // Reverse lookup: which routes serve a given stop (place).
            $table->index('place_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('route_stages');
    }
};
