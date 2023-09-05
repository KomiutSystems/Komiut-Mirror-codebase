<?php

use App\Models\Sacco;
use App\Models\Seat;
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
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string("plate")->unique();
            $table->string("fleet_no")->nullable();
            $table->string("till_number")->nullable();
            $table->string("merchant_short_code")->nullable();
            $table->foreignIdFor(Sacco::class)->nullable();
            $table->foreignIdFor(User::class);
            $table->foreignIdFor(Seat::class);
            $table->boolean('status')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
