<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One payment, one transaction: transactions.mpesa_id becomes UNIQUE.
 *
 * C2bPaymentRecorder checks "is there a transaction for this payment?" and then
 * inserts one. The two steps are not atomic, so two deliveries of the same
 * payment arriving together both passed the check and both inserted -- and the
 * day's takings counted the payment twice. Production held one such pair
 * (mpesa 26244105, 2026-09-26, two rows in the same second). It was not on a
 * bus, so no takings were inflated, but it proves the race is live.
 *
 * NO LOCKS ON LIVE PAYMENTS: transactions takes every confirmation, so the index
 * is built CONCURRENTLY (no lock that blocks inserts), which cannot run inside a
 * transaction -- hence $withinTransaction = false. See
 * 2026_08_26_100000_add_money_indexes_for_backfill for why every statement is
 * re-runnable and why an INVALID namesake is dropped before building.
 *
 * DUPLICATES FIRST: the unique build fails while any pair exists. Extra rows of
 * a pair that is NOT on a bus are removed (the lowest id is kept; each removed
 * row is logged in full). A pair ON a bus also double-counted that bus's
 * summary, which a delete alone would not undo, so the migration stops and
 * names it rather than guessing -- there were none on 2026-11-25.
 *
 * The old plain index on mpesa_id is dropped once the unique one is valid: the
 * unique index answers every lookup it served.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const UNIQUE = 'transactions_mpesa_id_unique';

    private const PLAIN = 'transactions_mpesa_id_index';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Twice: a pair could land between the clean-up and the build.
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $this->removeDuplicates();
            $this->dropIfInvalid(self::UNIQUE);
            try {
                DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS '.self::UNIQUE.' ON transactions (mpesa_id)');
                break;
            } catch (Throwable $e) {
                if ($attempt === 2) {
                    throw $e;
                }
            }
        }

        if ($this->isValid(self::UNIQUE)) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::PLAIN);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $this->dropIfInvalid(self::PLAIN);
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::PLAIN.' ON transactions (mpesa_id)');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::UNIQUE);
    }

    private function removeDuplicates(): void
    {
        $pairs = DB::select('SELECT mpesa_id, MIN(id) AS keep, COUNT(*) AS n, COUNT(vehicle_id) AS on_a_bus
            FROM transactions WHERE mpesa_id IS NOT NULL GROUP BY mpesa_id HAVING COUNT(*) > 1');

        foreach ($pairs as $pair) {
            if ((int) $pair->on_a_bus > 0) {
                throw new RuntimeException("transactions for mpesa {$pair->mpesa_id} are duplicated ON A BUS: "
                    .'its summary counted the payment more than once. Remove the extra row and rebuild that '
                    .'day\'s summary by hand, then re-run this migration.');
            }

            $extras = DB::table('transactions')->where('mpesa_id', $pair->mpesa_id)->where('id', '<>', $pair->keep)->get();
            foreach ($extras as $row) {
                Log::warning('one_transaction_per_payment: removed duplicate transaction', (array) $row);
                echo '  removed duplicate transaction '.json_encode($row).PHP_EOL;
            }
            DB::table('transactions')->whereIn('id', $extras->pluck('id'))->delete();
        }
    }

    private function isValid(string $index): bool
    {
        return (bool) DB::selectOne('SELECT i.indisvalid AS ok FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid
            WHERE c.relname = ? AND c.relnamespace = current_schema()::regnamespace', [$index])?->ok;
    }

    private function dropIfInvalid(string $index): void
    {
        $row = DB::selectOne('SELECT i.indisvalid AS ok FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid
            WHERE c.relname = ? AND c.relnamespace = current_schema()::regnamespace', [$index]);
        if ($row !== null && ! $row->ok) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$index);
        }
    }
};
