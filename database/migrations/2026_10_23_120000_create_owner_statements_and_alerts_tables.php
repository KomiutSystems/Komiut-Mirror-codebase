<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An owner's nightly statement, and the alerts raised when a bus goes quiet.
 *
 * Statements are STORED, not computed on read: a statement is what was sent, and
 * a payment that settles after it went out must not silently change it. The
 * public alert id is `al_<id>`, derived in the resource like lost-found's.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('owner_statements')) {
            Schema::create('owner_statements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
                $table->date('date');
                $table->json('totals');
                $table->json('vehicles');
                $table->timestamp('generated_at');
                // Null until the push actually goes out, so a statement stored
                // while the flag is off can still be pushed later.
                $table->timestamp('pushed_at')->nullable();
                $table->timestamps();

                // Makes a second run of the 5-minute sweep a no-op.
                $table->unique(['owner_id', 'date']);
            });
        }

        if (! Schema::hasTable('vehicle_alerts')) {
            Schema::create('vehicle_alerts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('vehicle_id')->constrained();
                $table->string('type', 40);
                $table->json('details')->nullable();
                $table->timestamp('raised_at');
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();

                // The episode lookup: this owner's open alert of this kind on
                // this bus. One alert per episode, not one every five minutes.
                $table->index(['vehicle_id', 'type', 'resolved_at']);
                $table->index(['owner_id', 'raised_at']);
            });
        }

        if (! Schema::hasTable('owner_notification_settings')) {
            Schema::create('owner_notification_settings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('owner_id')->unique()->constrained('users')->cascadeOnDelete();
                $table->boolean('statement_enabled')->default(true);
                // Nairobi local time, as the owner set it.
                $table->string('statement_time', 5)->default('21:00');
                $table->boolean('offline_alert_enabled')->default(true);
                $table->unsignedSmallInteger('offline_after_minutes')->default(30);
                $table->boolean('noon_alert_enabled')->default(true);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_notification_settings');
        Schema::dropIfExists('vehicle_alerts');
        Schema::dropIfExists('owner_statements');
    }
};
