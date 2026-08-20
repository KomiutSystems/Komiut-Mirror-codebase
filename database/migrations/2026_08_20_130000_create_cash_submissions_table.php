<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The driver's END-OF-SHIFT cash declaration, per vehicle per business day.
 *
 * This is a MANUAL reconciliation record: at knock-off a driver states the
 * cash they physically hold for the bus. It is not the M-Pesa callback and not
 * a `transactions` row — it is what the crew counted, to be set against what the
 * system recorded. Keyed on the VEHICLE, not the driver, because crews rotate
 * and the money belongs to the till, not the person; and on the BUSINESS DATE
 * (the 03:00-EAT day), so a night run counts into the day it started.
 *
 * The UNIQUE (vehicle_id, business_date) is the whole point: there is exactly
 * one declaration per vehicle per day, and a resubmission UPDATES it rather than
 * stacking a second count for the same shift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_submissions', function (Blueprint $table): void {
            $table->id();

            // The bus the cash belongs to. Indexed for the per-vehicle lookups
            // the driver app makes; cascades so a deleted vehicle takes its
            // declarations with it.
            $table->foreignId('vehicle_id')
                ->constrained('vehicles')
                ->cascadeOnDelete();
            $table->index('vehicle_id');

            // WHO declared it — the driver on shift. Kept for attribution across
            // a rotation; the row itself is owned by the vehicle+day, not the user.
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // The 03:00-EAT business day this declaration files under (a calendar
            // date, not a timestamp).
            $table->date('business_date');

            $table->decimal('declared_amount', 12, 2);
            $table->string('note')->nullable();
            $table->timestamps();

            // Exactly one declaration per vehicle per business day. The upsert in
            // the controller targets this pair; the DB guarantees it even under a
            // double-submit race.
            $table->unique(['vehicle_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_submissions');
    }
};
