<?php

use App\Models\ExpenseFee;
use App\Models\Vehicle;
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
        Schema::create('vehicle_expense_and_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Vehicle::class);
            $table->foreignIdFor(ExpenseFee::class);
            $table->unsignedDouble("amount");
            $table->dateTime("trans_date");
            $table->boolean("status")->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_expense_and_fees');
    }
};
