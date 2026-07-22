<?php

use App\Models\Queue;
use App\Models\Route;
use App\Models\Vehicle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live last-known position per vehicle. One row per vehicle (upserted on every
 * ping). Redis-free: the realtime *push* happens over Reverb the moment a ping
 * lands; this table is the queryable index for "vehicles near me / on my route".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Vehicle::class)->unique()->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Route::class)->nullable();
            $table->foreignIdFor(Queue::class)->nullable();
            $table->double('latitude');
            $table->double('longitude');
            $table->unsignedSmallInteger('heading')->default(0); // compass bearing 0–359
            $table->boolean('broadcasting')->default(true);
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();

            $table->index(['broadcasting', 'recorded_at']);
            $table->index(['latitude', 'longitude']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_locations');
    }
};
