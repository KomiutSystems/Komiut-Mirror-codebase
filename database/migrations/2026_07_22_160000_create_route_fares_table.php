<?php

use App\Models\Place;
use App\Models\Route;
use App\Models\Sacco;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-stop-pair fares, owned by a SACCO. This is the granular layer: a SACCO
 * that prices by segment sets a row per (from stop → to stop) on one of its
 * routes. When no row matches a requested pair, the resolver falls back to the
 * flat `sacco_routes.amount` the SACCO already sets for the whole route.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_fares', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Sacco::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Route::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Place::class, 'from_place_id')->constrained('places');
            $table->foreignIdFor(Place::class, 'to_place_id')->constrained('places');
            $table->double('amount')->unsigned();
            $table->boolean('status')->default(true);
            $table->timestamps();

            // One fare per direction per pair per SACCO+route.
            $table->unique(['sacco_id', 'route_id', 'from_place_id', 'to_place_id'], 'route_fares_pair_unique');
            // The resolver loads every fare for a (sacco, route) in one go.
            $table->index(['sacco_id', 'route_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_fares');
    }
};
