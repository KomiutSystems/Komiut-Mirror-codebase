<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record where each till's money has been told to go.
 *
 * The legacy tier stored one boolean, `tills.is_registered`, and nothing else —
 * no timestamp, no URL, no response. So a till that failed to register, one that
 * was registered and later re-pointed elsewhere, and one that was never touched
 * are indistinguishable rows. During a migration where the whole question is
 * "which tills have moved", that boolean answers nothing.
 *
 * `till_registered_url` is the important column, not the timestamp: it records
 * the ConfirmationURL Safaricom actually accepted, so "has this bus moved to
 * Frankfurt" is a string comparison against the current host rather than an
 * inference. A till re-registered back to Mumbai for a rollback shows Mumbai
 * here, which a boolean could never express.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->timestamp('till_registered_at')->nullable()->after('merchant_short_code');
            $table->string('till_registered_url', 255)->nullable()->after('till_registered_at');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['till_registered_at', 'till_registered_url']);
        });
    }
};
