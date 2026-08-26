<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A place to put an SMS reset password that is NOT the user's actual password.
 *
 * auth/reset_password is public and identifies people by phone number. It used
 * to overwrite `users.password` on the spot, which meant anyone who knew a
 * number could lock its owner out of their account — repeatedly, at whatever
 * rate the throttle allowed. The SMS went to the real owner, so the attacker
 * gained nothing except the ability to deny the account to its owner.
 *
 * Holding the temporary password beside the real one instead makes the reset
 * ADDITIVE: for as long as the window lasts, either password signs you in, and
 * using the temporary one consumes it. An unrequested reset then costs its
 * victim one confusing SMS rather than their account.
 *
 * The alternative — an OTP endpoint pair, request then set — is the textbook
 * design and would need every mobile client to ship a change first. This keeps
 * the existing one-call contract exactly as the apps already use it.
 *
 * Both columns are nullable with no default, so on PostgreSQL this is a
 * catalogue-only ALTER: no table rewrite, no lock held while 6,000+ rows are
 * copied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // HASHED, never the plaintext. It is a password for the window it
            // lives in, and a readable column of live passwords is exactly what
            // a database leak turns into account takeovers.
            $table->string('sms_reset_password')->nullable();

            // Without an expiry this is a permanent second password on the
            // account, which is worse than the bug it replaces.
            $table->timestamp('sms_reset_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['sms_reset_password', 'sms_reset_expires_at']);
        });
    }
};
