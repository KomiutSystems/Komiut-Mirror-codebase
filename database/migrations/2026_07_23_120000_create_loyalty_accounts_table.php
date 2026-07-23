<?php

use App\Models\Sacco;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A passenger's running points balance at one SACCO. One row per (user, sacco);
 * the balance is the materialised sum of the loyalty_transactions ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Sacco::class)->constrained()->cascadeOnDelete();
            $table->double('balance')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'sacco_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_accounts');
    }
};
