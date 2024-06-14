<?php

use App\Models\Place;
use App\Models\Point;
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
        Schema::create('redeemed_points', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Point::class);
            $table->unsignedDouble("redeemed_points");
            $table->foreignIdFor(Place::class, 'from_id')->nullable();
            $table->foreignIdFor(Place::class, 'to_id')->nullable();
            $table->boolean("status")->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('redeemed_points');
    }
};
