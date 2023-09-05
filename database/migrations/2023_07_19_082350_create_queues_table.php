<?php

use App\Models\QueueStatus;
use App\Models\Route;
use App\Models\Terminus;
use App\Models\Vehicle;
use App\Models\User;
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
        Schema::create('queues', function (Blueprint $table) {
            $table->id();
            $table->string("queue_number");
            $table->foreignIdFor(Vehicle::class);
            $table->foreignIdFor(Terminus::class);
            $table->foreignIdFor(QueueStatus::class);
            $table->foreignIdFor(Route::class);
            $table->foreignIdFor(User::class);
            $table->unsignedDouble('amount');
            $table->datetime('schedule_time')->nullable();
            $table->datetime('start_time')->nullable();
            $table->datetime('end_time')->nullable();
            $table->boolean('queue_type')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('queues');
    }
};
