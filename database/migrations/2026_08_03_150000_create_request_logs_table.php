<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * request_logs — one row per HTTP request handled by the API, written from the
 * LogHttpRequests terminable middleware AFTER the response is sent. This is the
 * "HTTP request log" the super-admin console reads so nobody has to SSH into a
 * box to see traffic.
 *
 * Append-only (created_at, no updated_at). We record method/path/status/duration
 * and who made the call — never request bodies, which can carry secrets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_logs', function (Blueprint $table) {
            $table->id();
            $table->string('method', 10);
            $table->string('path')->index();            // default length so the index is portable
            $table->unsignedSmallInteger('status')->index();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('brand')->nullable();        // from Context, may be null off-brand
            $table->string('ip', 45)->nullable();       // fits IPv6
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_logs');
    }
};
