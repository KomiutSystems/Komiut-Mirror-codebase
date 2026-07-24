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
        Schema::create('saccos', function (Blueprint $table) {
            $table->id();
            $table->string("name")->unique();          // the SACCO's identity — required at sign-up
            $table->string("email")->nullable()->unique(); // primary contact + login on self-registration
            $table->string("slogan")->nullable();
            $table->string("phone")->nullable();
            $table->unsignedInteger("status")->default(0); // 0 = pending/inactive, 1 = active
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('saccos');
    }
};
