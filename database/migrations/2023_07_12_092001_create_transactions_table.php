<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Vehicle;
use App\Models\Cash;
use App\Models\Mpesa;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Vehicle::class)->nullable();
            $table->foreignIdFor(Cash::class)->nullable();
            $table->foreignIdFor(Mpesa::class)->nullable();
            $table->unsignedDouble("amount");
            $table->datetime("trans_date");
            $table->boolean('redeemed')->default(false);
            $table->boolean('summarized')->default(false);
            $table->timestamps();

            $table->index(['trans_date', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
