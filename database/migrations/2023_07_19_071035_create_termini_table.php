<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Place;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('termini', function (Blueprint $table) {
            $table->id();
            $table->string("name");
            $table->double("longitude")->nullable();
            $table->double("latitude")->nullable();
            $table->foreignIdFor(Place::class);
            $table->boolean("status")->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('termini');
    }
};
