<?php
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
        Schema::create('summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Vehicle::class)->index();
            $table->double('mpesa_amount')->unsigned();
            $table->double('cash_amount')->unsigned();
            $table->unsignedInteger('mpesa_txn');
            $table->unsignedInteger('cash_txn');
            $table->date('trans_date')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('summaries');
    }
};
