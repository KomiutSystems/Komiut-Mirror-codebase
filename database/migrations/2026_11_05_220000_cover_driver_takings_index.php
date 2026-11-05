<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One index that answers a bus's takings without touching the table.
 *
 * transactions_vehicle_id_trans_date_index (vehicle_id, trans_date) is the
 * busiest index on the table (32M scans by 2026-11-05): every crew-app poll of
 * driver/home, driver/transactions and driver/earnings starts there. It finds
 * the bus's rows for a window, but the sums the earnings screen shows
 * (SUM(amount), split by mpesa_id > 0 / cash_id > 0) still have to read each
 * row from the heap. On 2026-10-23 that heap read is what turned fourteen crew
 * phones into an outage.
 *
 * The same key with amount, mpesa_id and cash_id carried in the leaf makes
 * those sums index-only scans, provided the visibility map is current, which
 * 2026_10_23_140000 arranged (transactions is vacuumed about daily). It
 * REPLACES the old index rather than sitting beside it: same leading columns,
 * so every query the old one served this one serves, and keeping both would
 * add a second 400 MB write cost to every payment. Net growth is the three
 * carried columns, about 24 bytes per row (~200 MB at 8.3M rows).
 *
 * Built CONCURRENTLY, so payments keep inserting while it builds. Run at
 * night: the build reads the whole 864 MB table twice.
 */
return new class extends Migration
{
    /** CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    private const NEW = 'transactions_vehicle_trans_date_covering_index';

    private const OLD = 'transactions_vehicle_id_trans_date_index';

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $this->dropIfInvalid(self::NEW);
        DB::unprepared('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::NEW
            .' ON transactions (vehicle_id, trans_date) INCLUDE (amount, mpesa_id, cash_id)');

        // Only once the replacement is usable: an interrupted build leaves an
        // INVALID index that the planner ignores, and dropping the old one then
        // would leave every crew poll scanning the table.
        if (! $this->isValid(self::NEW)) {
            throw new RuntimeException(self::NEW.' did not build valid; '.self::OLD.' kept');
        }
        DB::unprepared('DROP INDEX CONCURRENTLY IF EXISTS '.self::OLD);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::OLD.' ON transactions (vehicle_id, trans_date)');
        DB::unprepared('DROP INDEX CONCURRENTLY IF EXISTS '.self::NEW);
    }

    private function isValid(string $name): bool
    {
        return DB::selectOne(
            'select 1 as x from pg_class c join pg_index i on i.indexrelid = c.oid where c.relname = ? and i.indisvalid',
            [$name]
        ) !== null;
    }

    /** A CONCURRENTLY build that was interrupted leaves an INVALID index that IF NOT EXISTS would keep. */
    private function dropIfInvalid(string $name): void
    {
        $invalid = DB::selectOne(
            'select 1 as x from pg_class c join pg_index i on i.indexrelid = c.oid where c.relname = ? and not i.indisvalid',
            [$name]
        );
        if ($invalid !== null) {
            DB::unprepared("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
        }
    }
};
