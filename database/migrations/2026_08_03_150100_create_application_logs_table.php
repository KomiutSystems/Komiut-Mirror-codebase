<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * application_logs — framework / application log records, written by the custom
 * Monolog DatabaseLogHandler behind the 'database' log channel. Surfaces the same
 * information as storage/logs/laravel.log through the super-admin console so an
 * operator never needs shell access to read the app log.
 *
 * Append-only (created_at, no updated_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_logs', function (Blueprint $table) {
            $table->id();
            $table->string('level', 20)->index();       // debug | info | warning | error | ...
            $table->string('channel')->nullable();
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_logs');
    }
};
