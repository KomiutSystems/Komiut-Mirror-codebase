<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a bus's till stands with Safaricom, as the SACCO's own screen can show
 * it: registered, waiting for a head office to be chosen, or Safaricom's own
 * reason for refusing. A bus added on 2026-11-06 sat unregistered for 19 days
 * -- every fare on it unrecorded -- and nothing anywhere said so until the
 * SACCO emailed us. See App\Services\Mpesa\TillRegistrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            // WHICH till the registration above was for. Without it a bus whose
            // till number changed still read as registered -- the URL had not
            // changed -- and its new till was never registered at all.
            $table->string('till_registered_short_code', 20)->nullable()->after('till_registered_url');
            $table->string('till_registration_status', 32)->nullable()->after('till_registered_short_code');
            $table->string('till_registration_note', 255)->nullable()->after('till_registration_status');
            $table->timestamp('till_registration_attempted_at')->nullable()->after('till_registration_note');
        });

        // Not backfilled: a till could be edited after its registration
        // without being registered again, so the current till proves nothing.
        // The column fills on the next registration, and until then nothing
        // is taken as registered on its say-so.
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['till_registered_short_code', 'till_registration_status', 'till_registration_note', 'till_registration_attempted_at']);
        });
    }
};
