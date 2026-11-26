<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Index the passenger's Payments tab and the sweep that keeps it honest.
 *
 * THE LIST (passenger/payments) is two keyset walks merged by time: one over
 * the caller's bookings, one over their QR fares, each `user_id = ? ORDER BY
 * created_at DESC, id DESC` with a `(created_at, id) < (?, ?)` cursor. The
 * existing (user_id, created_at) indexes serve the filter and the sort but
 * not the tiebreak, and created_at is to the second -- a passenger's booking
 * and its STK land in the same one routinely. (user_id, created_at, id)
 * answers all three in one seek, whatever the page. The two-column indexes
 * stay: dropping a live index belongs in its own change.
 *
 * THE ROWS BEHIND EACH ITEM are found by their owner, and none of these had an
 * index on it: a push by its booking or QR fare (also what openPush asks on
 * every "pay" tap), a receipt by its booking or QR fare. Each was a scan of
 * the whole table per lookup. (owner, id) on the pushes because what is read
 * is the LATEST push for an owner.
 *
 * THE SWEEP (payments:reconcile, every two minutes) asks for unanswered pushes
 * by age. Partial on processed_at IS NULL, so it holds only the handful still
 * open rather than every push ever raised.
 *
 * CONCURRENTLY, outside a transaction, and repairing a half-built namesake
 * first -- see 2026_08_27_140000_add_money_keyset_indexes for why each.
 */
return new class extends Migration
{
    /** Required by CREATE/DROP INDEX CONCURRENTLY. */
    public $withinTransaction = false;

    /** index name => [table, column list, partial predicate or null] */
    private const INDEXES = [
        'qrcode_payments_user_id_created_at_id_index' => ['qrcode_payments', ['user_id', 'created_at', 'id'], null],
        'bookings_user_id_created_at_id_index' => ['bookings', ['user_id', 'created_at', 'id'], null],
        'mpesa_stk_callbacks_booking_id_id_index' => ['mpesa_stk_callbacks', ['booking_id', 'id'], null],
        'mpesa_stk_callbacks_qrcode_payment_id_id_index' => ['mpesa_stk_callbacks', ['qrcode_payment_id', 'id'], null],
        'mpesa_stk_callbacks_open_created_at_index' => ['mpesa_stk_callbacks', ['created_at'], 'processed_at IS NULL'],
        'mpesa_booking_callbacks_booking_id_index' => ['mpesa_booking_callbacks', ['booking_id'], null],
        'mpesa_qrcode_payments_qrcode_payment_id_index' => ['mpesa_qrcode_payments', ['qrcode_payment_id'], null],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => [$table, $columns, $where]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (DB::connection()->getDriverName() !== 'pgsql') {
                if (! Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
                }

                continue;
            }

            $this->dropIfInvalid($name);

            DB::unprepared(sprintf(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (%s)%s',
                $this->quote($name),
                $this->quote($table),
                implode(', ', array_map($this->quote(...), $columns)),
                $where === null ? '' : ' WHERE '.$where,
            ));
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $name => [$table]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::unprepared('DROP INDEX CONCURRENTLY IF EXISTS '.$this->quote($name));

                continue;
            }

            if (Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
            }
        }
    }

    /**
     * A CONCURRENTLY build that failed leaves an INVALID index: never used by
     * the planner, still maintained on every insert. `IF NOT EXISTS` matches on
     * name and would skip right over it, so drop it first and genuinely repair.
     */
    private function dropIfInvalid(string $name): void
    {
        $invalid = DB::selectOne(
            'SELECT 1 FROM pg_class c JOIN pg_index i ON i.indexrelid = c.oid
             WHERE c.relname = ? AND i.indisvalid = false',
            [$name]
        );

        if ($invalid !== null) {
            DB::unprepared('DROP INDEX CONCURRENTLY IF EXISTS '.$this->quote($name));
        }
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
};
