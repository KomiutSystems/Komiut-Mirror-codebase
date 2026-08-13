<?php

use App\Models\Sacco;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A SACCO's messages to its own crew.
 *
 * The notification machinery already fans one dispatch out to in-app, realtime
 * and push. What was missing was a SACCO-side WAY IN: a matatu SACCO had no
 * channel to its drivers and conductors at all, so "no service on Mashujaa Day"
 * or "fuel levy changes Monday" travelled by WhatsApp group, if at all.
 *
 * A row per announcement rather than a fire-and-forget loop, for three reasons:
 *
 *  1. NotificationService dedupes on (recipient, referenceId, title) and SKIPS
 *     the guard entirely when referenceId is null. A mass push with no id would
 *     re-notify every driver on a job retry. The row's id IS that reference.
 *  2. The SACCO needs to see what it has already sent, and to whom — otherwise
 *     the same notice goes out three times because nobody could tell.
 *  3. `recipients` records the fan-out actually achieved, which is the only way
 *     to notice later that a send reached four people instead of two hundred.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sacco_announcements', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(Sacco::class)->index();
            $table->foreignIdFor(User::class)->comment('who sent it');
            // Set when the announcement targets one bus's crew rather than the
            // whole SACCO. Null = everyone.
            $table->foreignIdFor(Vehicle::class)->nullable();
            $table->string('title');
            $table->text('body');
            $table->unsignedInteger('recipients')->default(0);
            $table->string('brand')->nullable()->index();
            $table->timestamps();

            // The SACCO's own list, newest first — the only read path there is.
            $table->index(['sacco_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sacco_announcements');
    }
};
