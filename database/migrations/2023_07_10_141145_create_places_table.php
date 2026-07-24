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
        Schema::create('places', function (Blueprint $table) {
            $table->id();
            $table->string("name");
            $table->string("county_name")->nullable();
            $table->double("longitude")->nullable();
            $table->double("latitude")->nullable();
            $table->boolean('status')->default(1);
            $table->timestamps();

            // Supports the legacy name-based stop search (id-based is preferred).
            $table->index('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('places');
    }
};
