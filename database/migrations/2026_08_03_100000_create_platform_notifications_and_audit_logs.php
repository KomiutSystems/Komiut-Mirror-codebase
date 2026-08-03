<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The super-admin console backbone.
 *
 * platform_notifications — the cross-brand event feed (envelope from the spec:
 * event/severity/class/brand/actor/subject/data/dedupe_key/count). NOT
 * brand-scoped: super admin is above the brand boundary, so brand is a column to
 * filter on, not a wall.
 *
 * audit_logs — the immutable trail written BEFORE a money/PII/privilege
 * notification, so an incident can be reconstructed even if the notification was
 * dismissed. Append-only (created_at, no updated_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('event')->index();          // e.g. vehicle.payment_details.changed
            $table->string('severity');                 // critical | high | normal
            $table->string('delivery_class');           // alert | review | digest
            $table->string('brand')->nullable()->index();
            $table->string('title');
            $table->string('summary', 300);
            $table->json('actor')->nullable();          // {type,id,label}
            $table->json('subject')->nullable();        // {type,id,label}
            $table->json('data')->nullable();
            $table->string('dedupe_key')->nullable();
            $table->unsignedInteger('count')->default(1);
            $table->foreignId('audit_id')->nullable();
            $table->timestamp('first_occurred_at')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable();
            $table->timestamps();

            // Dedup/throttle lookups: same key, still open, within its window.
            $table->index(['dedupe_key', 'resolved_at']);
            $table->index(['delivery_class', 'read_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('action')->index();
            $table->string('actor_type')->nullable();   // user | partner | driver | system
            $table->string('actor_id')->nullable();
            $table->string('actor_label')->nullable();
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->string('brand')->nullable()->index();
            $table->json('data')->nullable();            // before/after, counts, refs — never PII
            $table->string('ip')->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_notifications');
        Schema::dropIfExists('audit_logs');
    }
};
