<?php

use App\Models\Sacco;
use App\Models\Terminus;
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
        Schema::create('sacco_termini', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Terminus::class);
            $table->foreignIdFor(Sacco::class);
            $table->foreignIdFor(User::class);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sacco_termini');
    }
};
