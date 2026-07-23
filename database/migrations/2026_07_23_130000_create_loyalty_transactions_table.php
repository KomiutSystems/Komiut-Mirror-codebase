<?php

use App\Models\Booking;
use App\Models\Sacco;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only ledger of point movements. `value` is signed (earn +, redeem −).
 * The unique (booking_id, type) index makes earning/redeeming idempotent per
 * booking — a replayed BookingPaid event can't double-credit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Sacco::class)->constrained()->cascadeOnDelete();
            $table->double('value');                 // signed: earn +, redeem/reverse −
            $table->string('type');                  // earned | redeemed | reversed | refunded
            $table->foreignIdFor(Booking::class)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'sacco_id']);
            // Idempotency: at most one earn/redeem/… per booking. NULL booking_id
            // rows (manual adjustments) are exempt on Postgres/SQLite.
            $table->unique(['booking_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_transactions');
    }
};
