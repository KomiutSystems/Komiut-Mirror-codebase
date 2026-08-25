<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record WHICH registered C2B callback delivered a payment.
 *
 * The legacy payment tier registers its Safaricom confirmation URLs per till, as
 * `{APP_URL}/api/confirmation/{mpesa_setting_id}` (see
 * MpesaAPIController::mpesaRegisterTillUrls on payments server 2), and stamps the
 * id from that URL onto every row it writes. The column is how you tell, from the
 * data alone, which of the ~178 live shortcodes is pointed where.
 *
 * That matters because the migration off the legacy tier is per-till: each till
 * is re-registered against this system one at a time and can be re-registered
 * back just as quickly. Without this column, "which tills have moved" is only
 * answerable by asking Safaricom till by till. With it, it is a GROUP BY.
 *
 * Nullable, because the other two ingestion paths have no such id: the NCBA
 * aggregator webhook and the Co-op endpoint are single fixed URLs, not per-till
 * registrations. A NULL here means "arrived by a route that has no setting id",
 * not "unknown".
 *
 * Deliberately NOT a foreign key. The id is a primary key in the LEGACY
 * `komiut_payments` database on another host in another region; the local
 * `mpesa_payment_settings` table is a different id space entirely. Constraining
 * it would either fail on every insert or silently invite the two id spaces to be
 * confused, which is exactly the mistake this column exists to make visible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mpesas') || Schema::hasColumn('mpesas', 'mpesa_setting_id')) {
            return;
        }

        Schema::table('mpesas', function (Blueprint $table): void {
            $table->unsignedBigInteger('mpesa_setting_id')->nullable()->after('TransactionType');
            // The reporting query is "how much has arrived on each migrated
            // till", i.e. group/filter on this column alone.
            $table->index('mpesa_setting_id', 'mpesas_mpesa_setting_id_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('mpesas') || ! Schema::hasColumn('mpesas', 'mpesa_setting_id')) {
            return;
        }

        Schema::table('mpesas', function (Blueprint $table): void {
            $table->dropIndex('mpesas_mpesa_setting_id_index');
            $table->dropColumn('mpesa_setting_id');
        });
    }
};
