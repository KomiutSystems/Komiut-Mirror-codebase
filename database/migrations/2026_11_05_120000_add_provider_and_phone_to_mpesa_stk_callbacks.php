<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which rail a push went out on, and to which phone.
 *
 * `provider` is null for Safaricom's own STK push (every row before this) and
 * 'ncba' for NCBA Till STK Push. The two settle differently: Daraja calls us
 * back on the nonce, NCBA has no callback and is queried (PollNcbaStkPush,
 * payments:reconcile), so each poller has to tell its rows apart.
 *
 * `phone` because NCBA's query answer is only SUCCESS or FAILED. Daraja's
 * callback carries the payer's number, which the settlement needs for the
 * receipt row and for crediting a QR payer's rewards; for NCBA the only record
 * of it is the number we pushed to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mpesa_stk_callbacks', function (Blueprint $table): void {
            $table->string('provider', 16)->nullable()->after('checkout_request_id');
            $table->string('phone', 16)->nullable()->after('provider');
        });
    }

    public function down(): void
    {
        Schema::table('mpesa_stk_callbacks', function (Blueprint $table): void {
            $table->dropColumn(['provider', 'phone']);
        });
    }
};
