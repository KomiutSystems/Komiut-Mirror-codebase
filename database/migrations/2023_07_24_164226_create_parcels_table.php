<?php

use App\Models\Vehicle;
use App\Models\User;
use App\Models\Place;
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
        Schema::create('parcels', function (Blueprint $table) {
            $table->id();
            $table->string("recipient_name");
            $table->string("recipient_phone");
            $table->string("recipient_idno")->nullable();
            $table->string("sender_name");
            $table->string("sender_phone");
            $table->string("sender_idno");
            $table->string("name");
            $table->text("description")->nullable();
            $table->foreignIdFor(Place::class, 'from_id');
            $table->foreignIdFor(Place::class, 'to_id');
            $table->foreignIdFor(User::class, 'sender_id')->nullable();
            $table->foreignIdFor(User::class, 'recipient_id')->nullable();
            $table->foreignIdFor(User::class, 'created_by');
            $table->foreignIdFor(Vehicle::class);
            $table->double("amount")->unsigned();
            $table->datetime("arrival_time")->nullable();
            $table->datetime("picking_time")->nullable();
            $table->enum("status", ["Pending", "Sent", "Recieved", "Cancelled"])->default("Pending");
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parcels');
    }
};
