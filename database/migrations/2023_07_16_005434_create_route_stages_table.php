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
            $table->double("distance")->unsigned()->nullable();
            $table->boolean('status')->default(1);
            $table->timestamps();
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
