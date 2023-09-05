<?php

use App\Models\Queue;
use App\Models\RouteStage;
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
        Schema::create('queue_places', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Queue::class);
            $table->foreignIdFor(RouteStage::class);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('queue_places');
    }
};
