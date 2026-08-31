<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keep the one number that can prove our records are complete.
 *
 * Safaricom stamps every C2B confirmation with OrgAccountBalance — the till's
 * balance immediately AFTER that payment. It is a running ledger written by the
 * payment network itself, and we have been throwing it away: it arrives in the
 * body, C2bPaymentRecorder never reads it, and `mpesas` has no column for it. It
 * survives only by accident, inside the raw JSON kept in `mpesa_logs`.
 *
 * WHY IT MATTERS. For consecutive confirmations on one till,
 * balance(n) - balance(n-1) must equal amount(n). Where it does not, money
 * entered that till which never reached us. That makes completeness checkable
 * against SAFARICOM rather than against ourselves — no portal download, no
 * statement, and crucially no legacy system.
 *
 * PROVEN BEFORE BUILDING. Reconstructed from mpesa_logs for KDX 439C on
 * 2026-08-31, the balance gaps between 06:37 and 07:44 summed to exactly
 * KES 850.00 — the same figure, to the shilling, as the 16 named receipts the
 * legacy system holds and we do not. Two independent sources, one answer.
 *
 * AND IT FOUND MORE THAN LEGACY DID. On 2026-08-30, a closed day, comparing
 * against legacy said KES 8,745 was missing; the balance ledger said KES 20,890.
 * The difference is money NEITHER system recorded — so legacy was never a
 * complete reference, and it is switched off this week regardless. After that
 * this column is the only cross-check we have left.
 *
 * NUMERIC, NOT VARCHAR. TransAmount is a varchar holding money, which is why
 * PaymentsController carries a transAmountSum() helper doing
 * SUM(CAST(NULLIF(col,'') AS DECIMAL)) to work around it. This column is
 * arithmetic from the first day it exists; storing it as text would import that
 * problem into the one place that can least afford it.
 *
 * Nullable, with no default, so on Postgres the ALTER is a catalogue change
 * rather than a rewrite of 1.5 million rows. Rows written before this migration
 * keep NULL and are filled by payments:backfill-balances from mpesa_logs.
 *
 * THE INDEX is what makes the audit affordable. `mpesas` has BusinessShortCode
 * and TransTime indexed separately, but the audit walks ONE till in time order —
 * a composite is the difference between a range scan and a filter over 1.5M
 * rows, on a query that runs per till per day.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    private const INDEX = 'mpesas_shortcode_transtime_index';

    public function up(): void
    {
        if (! Schema::hasColumn('mpesas', 'OrgAccountBalance')) {
            Schema::table('mpesas', function (Blueprint $table): void {
                $table->decimal('OrgAccountBalance', 15, 2)->nullable()->after('TransAmount');
            });
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            // sqlite (tests) has no CONCURRENTLY and needs no such index.
            Schema::table('mpesas', function (Blueprint $table): void {
                if (! $this->indexExists()) {
                    $table->index(['BusinessShortCode', 'TransTime'], self::INDEX);
                }
            });

            return;
        }

        $this->dropIfInvalid(self::INDEX);

        DB::unprepared(sprintf(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON mpesas ("BusinessShortCode", "TransTime")',
            self::INDEX
        ));
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
        }

        if (Schema::hasColumn('mpesas', 'OrgAccountBalance')) {
            Schema::table('mpesas', function (Blueprint $table): void {
                $table->dropColumn('OrgAccountBalance');
            });
        }
    }

    private function indexExists(): bool
    {
        try {
            return Schema::getConnection()
                ->getSchemaBuilder()
                ->hasIndex('mpesas', self::INDEX);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Clear the wreckage of a previously interrupted CONCURRENTLY build.
     *
     * Only matches indexes already marked invalid, so it can never drop a
     * healthy one. It cannot tell "failed earlier" from "another session is
     * building this right now" — both read as indisvalid = false — so two copies
     * of this migration must not run at once.
     */
    private function dropIfInvalid(string $name): void
    {
        $invalid = DB::selectOne(
            'select 1 from pg_class c
               join pg_index i on i.indexrelid = c.oid
              where c.relname = ? and i.indisvalid = false',
            [$name]
        );

        if ($invalid !== null) {
            DB::unprepared('DROP INDEX CONCURRENTLY IF EXISTS '.$name);
        }
    }
};
