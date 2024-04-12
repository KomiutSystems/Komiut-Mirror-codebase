<?php

use App\Models\Sacco;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('point_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedDouble("amount")->nullable();
            $table->unsignedDouble("items")->nullable();
            $table->enum("points_on", ["queues", "bookings"]);
            $table->enum("points_type", ["by amount", "by items"]);
            $table->datetime('start_date')->useCurrent();
            $table->foreignIdFor(Role::class)->nullable();
            $table->foreignIdFor(Sacco::class)->nullable();
            $table->boolean("completed")->default(false);
            $table->boolean("status")->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('point_settings');
    }
};
