<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A passenger reports something left on a matatu; the crew on that bus answers.
 *
 * The public id is `lf_<id>` and the reference `LF-<id>`, both derived from this
 * table's own auto-increment in LostItemReportResource rather than stored. Two
 * passengers reporting at the same moment cannot collide that way, which a
 * hand-built reference could.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lost_item_reports')) {
            Schema::create('lost_item_reports', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('vehicle_id')->constrained();
                $table->unsignedBigInteger('booking_id')->nullable();
                $table->unsignedBigInteger('sacco_id')->nullable();
                $table->string('brand')->nullable();

                $table->string('category', 20);
                $table->string('description', 200);
                // Only the SACCO office ever reads this; it is kept out of both
                // the passenger and the crew payloads.
                $table->string('contact_phone', 20)->nullable();
                $table->date('trip_date');
                $table->string('route_label', 120)->nullable();
                $table->string('status', 20)->default('open');

                $table->string('found_where', 20)->nullable();
                $table->string('found_place_name', 120)->nullable();
                $table->string('found_note', 200)->nullable();
                $table->string('found_photo_path')->nullable();
                $table->unsignedBigInteger('found_by')->nullable();
                $table->timestamp('found_at')->nullable();
                $table->date('keep_until')->nullable();

                // Set when the crew have actually been pushed. A report filed
                // with nobody signed in waits here until someone does.
                $table->timestamp('crew_notified_at')->nullable();

                $table->timestamps();

                $table->index(['vehicle_id', 'status']);
                $table->index(['user_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('lost_item_events')) {
            Schema::create('lost_item_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('report_id')->constrained('lost_item_reports')->cascadeOnDelete();
                $table->string('status', 20);
                $table->string('note', 200)->nullable();
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->timestamps();

                $table->index(['report_id', 'id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lost_item_events');
        Schema::dropIfExists('lost_item_reports');
    }
};
