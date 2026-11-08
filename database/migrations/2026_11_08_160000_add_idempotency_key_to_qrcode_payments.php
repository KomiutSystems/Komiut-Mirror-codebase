<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The client's own key for a tap, so a retry cannot become a second fare.
 *
 * WHY THE THREE-MINUTE WINDOW IS NOT ENOUGH ON ITS OWN. recentScanRedemption
 * treats any second scan of the same bus within a few minutes as the same
 * boarding, which is right for points: a points ride is one passenger's own
 * reward and nobody redeems twice in a row deliberately.
 *
 * Real money breaks that assumption. Paying for a companion is ordinary on a
 * matatu -- two fares, same bus, seconds apart -- and under a window-only rule
 * the second one comes back as the FIRST receipt with `replayed: true`. The
 * passenger is never charged, the conductor is shown a paid screen, and the
 * SACCO loses the fare.
 *
 * The app already mints a key per payment (core/network/idempotency.dart, sent
 * as a header by pay()). Keyed on that, a retry replays and a second genuine
 * fare does not. The window survives only as a fallback for a client that sends
 * no key, and there it also has to match on amount.
 *
 * Nullable because every existing row predates this and because the points path
 * does not use it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qrcode_payments', function (Blueprint $table) {
            $table->string('idempotency_key', 120)->nullable()->after('payment_method');
            $table->unique('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('qrcode_payments', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
