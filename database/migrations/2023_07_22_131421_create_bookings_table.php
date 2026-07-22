<?php

use App\Models\Place;
use App\Models\User;
use App\Models\Queue;
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
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string("name");
            $table->string("phone");
            $table->unsignedInteger("passengers");
            $table->foreignIdFor(User::class)->nullable();
            $table->foreignIdFor(Queue::class);
            $table->foreignIdFor(Place::class, 'from_id');
            $table->foreignIdFor(Place::class, 'to_id');
            $table->double("amount")->unsigned();
            $table->boolean('boarded')->default(false);
            $table->boolean('paid')->default(false);
            $table->datetime("start_time")->nullable();
            $table->datetime("stop_time")->nullable();
            $table->foreignIdFor(User::class, 'created_by');
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
