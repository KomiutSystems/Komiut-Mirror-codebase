<?php

use App\Models\Route;
use App\Models\Sacco;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sacco_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Sacco::class);
            $table->foreignIdFor(Route::class);
            $table->foreignIdFor(User::class);
            $table->double("amount")->unsigned();
            $table->double("min_amount")->unsigned();
            $table->boolean("status")->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sacco_routes');
    }
};
