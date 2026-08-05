<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The IPs a partner bank has successfully authenticated from.
 *
 * The partner portal has ONE shared key per bank and no individual accounts, so
 * the only accountability is where access came from. This table remembers each
 * (partner, ip) the first time it appears and refreshes last_seen_at on every
 * later hit, so App\Services\Super\Fraud\PartnerAccessOrigins can decide whether a
 * successful auth arrived from an origin not seen in the last 30 days — the signal
 * behind partner.access.new_origin.
 *
 * PII-free by construction: only the partner key and the source IP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_access_origins', function (Blueprint $table): void {
            $table->id();
            $table->string('partner')->index();
            $table->string('ip', 45); // holds an IPv6 literal
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();

            $table->unique(['partner', 'ip']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_access_origins');
    }
};
