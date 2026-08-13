<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The lead list becomes an application.
 *
 * NOTE — this deliberately reverses what the create_driver_bank_leads docblock
 * says. That table was built as "a lead list handed to a partner bank, not an
 * application: no account numbers, no balances." The decision changed: NCBA
 * lets a driver open an account from their own phone, so the agent can capture
 * the account number on the spot and we hand the bank something it can act on
 * rather than a name to chase.
 *
 * `account_number` is PII, not a secret, and is deliberately NOT encrypted.
 * Access control is the protection — the table is brand-scoped and reachable
 * only through the partner portal and the super console. Every encrypted column
 * also raises the cost of the pending Frankfurt APP_KEY rotation, which is a
 * real price to pay for no real gain against an attacker who already has the
 * database.
 *
 * CONSENT stands in for a signature. An agent onboards a driver at a stage on a
 * phone; there is nowhere to sign, and the driver's name, phone and ID number
 * are about to be sent to a bank. So a boolean is not enough:
 *
 *   consent_text_version  WHAT they agreed to. The disclosure wording will
 *                         change; an old consent must still say what it covered,
 *                         and a bare `consented = true` cannot.
 *   consent_agent         WHO attested. Onboarding is unauthenticated (the
 *                         driver has no account yet), so there is no
 *                         $request->user() to fall back on — without this the
 *                         record names nobody and is worth very little.
 *   consent_ip            the origin, as weak corroboration of the above.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_bank_leads', function (Blueprint $table): void {
            $table->string('account_number')->nullable()->after('preferred_branch');
            $table->timestamp('consent_given_at')->nullable()->after('opted_in_at');
            $table->string('consent_text_version')->nullable()->after('consent_given_at');
            $table->string('consent_agent')->nullable()->after('consent_text_version');
            $table->string('consent_ip', 45)->nullable()->after('consent_agent');
            // Written back by the bank through the partner portal.
            $table->timestamp('account_opened_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('driver_bank_leads', function (Blueprint $table): void {
            $table->dropColumn([
                'account_number', 'consent_given_at', 'consent_text_version',
                'consent_agent', 'consent_ip', 'account_opened_at',
            ]);
        });
    }
};
