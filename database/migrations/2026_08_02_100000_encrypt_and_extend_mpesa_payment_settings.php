<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prepare mpesa_payment_settings for encrypted credentials + a SACCO paybill.
 *
 * The credential columns were string (VARCHAR 255); an encrypted value is a
 * base64 JSON envelope that overruns 255, so widen them to TEXT before the
 * EncryptedLegacyString cast starts writing ciphertext. Existing plaintext
 * values are preserved by the widen and read back transparently by the cast.
 *
 * `paybill` is the SACCO's customer-facing collection number (shared across its
 * vehicles); the Tills dashboard shows it per vehicle via this one setting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mpesa_payment_settings', function (Blueprint $table) {
            $table->text('consumer_key')->change();
            $table->text('consumer_secret')->change();
            $table->text('pass_key')->change();

            if (! Schema::hasColumn('mpesa_payment_settings', 'paybill')) {
                $table->string('paybill')->nullable()->after('business_short_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('mpesa_payment_settings', function (Blueprint $table) {
            if (Schema::hasColumn('mpesa_payment_settings', 'paybill')) {
                $table->dropColumn('paybill');
            }
            // Column types are intentionally left as TEXT: narrowing back to
            // VARCHAR could truncate an encrypted value written since this ran.
        });
    }
};
