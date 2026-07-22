<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Route;
use App\Models\Place;
use App\Models\User;
use App\Models\Vehicle;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cashes', function (Blueprint $table) {
            $table->id();
            $table->string("trans_id")->unique();
            $table->foreignIdFor(Route::class)->nullable();
            $table->foreignIdFor(Place::class, 'from_id')->nullable();
            $table->foreignIdFor(Place::class, 'to_id')->nullable();
            $table->foreignIdFor(User::class)->nullable();
            $table->foreignIdFor(Vehicle::class)->nullable();
            $table->string("firstname")->nullable();
            $table->string("lastname")->nullable();
            $table->string("phone")->nullable();
            $table->unsignedInteger("passengers")->nullable();
            $table->double("recieved_amount")->unsigned();
            $table->double("fare_amount")->unsigned();
            $table->double("luggage_amount")->unsigned();
            $table->double("total_amount")->unsigned();
            $table->double("change_amount")->unsigned();
            $table->datetime("trans_date");
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cashes');
    }
};
