<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The open fraud flags the ops board's fraud cards follow
 * (App\Services\Team\AutoCards\FraudCards::EVENTS, unresolved alerts).
 *
 * Closing a card asks "does any open flag still name this bus or SACCO?" --
 * on the console's Resolve click and on every hourly sweep. Without this the
 * question walks every open alert of every kind; with it, only the open
 * fraud flags, which the team keeps short by resolving them. PARTIAL, so it
 * holds nothing else: a resolved flag leaves it.
 *
 * The event list is written out, not read from FraudCards: a migration must
 * build the same index whenever it runs. FraudCards' query names a subset of
 * it, which is what lets the planner use it; a new event there needs a new
 * index here.
 *
 * CONCURRENTLY: platform_notifications is written on request paths (a till
 * edit, a login burst), and a plain build would hold those writes for its
 * duration. That needs running outside a transaction.
 */
return new class extends Migration
{
    /** Required by CREATE/DROP INDEX CONCURRENTLY. */
    public $withinTransaction = false;

    private const NAME = 'platform_notifications_open_fraud_flags_index';

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // A failed CONCURRENTLY build leaves an INVALID index that IF NOT
        // EXISTS would skip over: drop it and build it properly.
        $invalid = DB::selectOne(
            'SELECT 1 FROM pg_class c JOIN pg_index i ON i.indexrelid = c.oid WHERE c.relname = ? AND i.indisvalid = false',
            [self::NAME]
        );
        if ($invalid !== null) {
            DB::unprepared('DROP INDEX CONCURRENTLY IF EXISTS '.self::NAME);
        }

        DB::unprepared('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::NAME.' ON platform_notifications (occurred_at DESC)'
            ." WHERE resolved_at IS NULL AND delivery_class = 'alert' AND event IN ("
            ."'driver.reassigned.rapid', 'driver.login.failed_burst', 'driver.login.suspicious_success', "
            ."'driver.id_number.duplicate', 'onboarding.velocity.spike', 'access.login.failed_burst', "
            ."'vehicle.till.duplicate', 'vehicle.plate.cross_sacco', 'loyalty.redemption.spike')");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP INDEX CONCURRENTLY IF EXISTS '.self::NAME);
    }
};
