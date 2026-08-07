<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MONEY-domain composite indexes.
 *
 * Every read in this domain is shaped the same way: "one vehicle (or a list of
 * them) over a date window". The schema only ever indexed those two dimensions
 * SEPARATELY — `transactions(vehicle_id)` and `transactions(trans_date)`, and the
 * same split on `cashes` and `summaries`. PostgreSQL can only use one of them per
 * scan (or BitmapAnd both and pay for the recheck), so a per-vehicle day query
 * reads every transaction for that day, or every transaction for that vehicle
 * ever. This is the exact failure mode that crippled the legacy 20.5M-row
 * `transactions` table: not a missing index, a missing COMPOSITE index.
 *
 * Every index below is (equality..., range/sort) — equality columns first so the
 * range column stays the last, contiguous part of the scan, which also lets the
 * index satisfy `ORDER BY trans_date DESC` as a backward index scan rather than
 * a sort node.
 *
 * ADDITIVE ONLY. Three of these make an existing single-column index
 * prefix-redundant (called out per index). They are deliberately NOT dropped:
 * this project has no database backups (only ad-hoc dumps), so a schema change
 * that removes something is effectively irreversible. Reclaiming that write
 * throughput is a separate, deliberate change.
 *
 * LOCKING — WHY `$withinTransaction = false` IS HERE. DO NOT REMOVE IT.
 *
 * A plain `CREATE INDEX` takes a SHARE lock on the table: concurrent reads are
 * fine, but every INSERT blocks for the duration of the build. On a 20M-row
 * `transactions` table taking live Daraja C2B webhooks that is minutes of
 * rejected customer payments. So on PostgreSQL every index below is built with
 * `CREATE INDEX CONCURRENTLY`, which takes no blocking lock.
 *
 * `CONCURRENTLY` cannot run inside a transaction block, and Laravel wraps each
 * migration in one on PostgreSQL. `public $withinTransaction = false` is what
 * disables that wrapper. Delete it and every statement below fails with
 * "CREATE INDEX CONCURRENTLY cannot run inside a transaction block". The same
 * applies to `DROP INDEX CONCURRENTLY` in `down()`.
 *
 * The price of leaving the transaction is that a mid-way failure is no longer
 * rolled back: some indexes exist and the migration is not recorded. Two things
 * make the re-run safe:
 *
 *   - `IF NOT EXISTS` on every create, so already-built indexes are skipped
 *     instead of erroring.
 *   - An INVALID-index sweep. A `CONCURRENTLY` build that fails (deadlock,
 *     cancelled statement, unique violation) leaves the index behind with
 *     `pg_index.indisvalid = false`. Such an index is never used by the planner
 *     but IS still maintained on every INSERT — the worst of both worlds — and
 *     `IF NOT EXISTS` only matches on NAME, so a naive re-run would skip right
 *     over it and leave the trap in place forever. `dropIfInvalid()` therefore
 *     drops any invalid namesake before creating, so a re-run genuinely repairs.
 *     Caveat: an index another session is building CONCURRENTLY right now is
 *     also `indisvalid = false`, so do not run two copies of this migration at
 *     the same time.
 *
 * Non-PostgreSQL drivers (sqlite, MySQL) do not support this syntax and keep the
 * portable Schema-builder path, which produces the same index names and the same
 * columns in the same order.
 */
return new class extends Migration
{
    /**
     * Disables Laravel's per-migration transaction. Required by
     * CREATE/DROP INDEX CONCURRENTLY. See the class docblock before touching.
     */
    public $withinTransaction = false;

    /**
     * The indexes this migration adds: table => [index name => columns].
     *
     * The comment above each entry states the exact query it serves and why the
     * columns are in that order.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private const INDEXES = [
        'transactions' => [
            // Serves, verbatim:
            //   TransactionsAPIController::getTransactions —
            //     WHERE transactions.trans_date BETWEEN :day AND :day+1
            //       AND transactions.vehicle_id IN (...)
            //     ORDER BY transactions.trans_date DESC LIMIT 20 OFFSET n
            //   TillsOverviewController::index (volume_30d) —
            //     WHERE vehicle_id IN (...) AND trans_date >= now()-30d => SUM(amount)
            //   HomeAPIController (all three chart branches) —
            //     WHERE trans_date BETWEEN ... AND vehicle_id IN (...)  => SUM(amount)
            //
            // Column order: vehicle_id is the equality/IN predicate, trans_date
            // is both the range AND the sort key. (vehicle_id, trans_date) lets
            // Postgres do one bounded range scan per vehicle id and return rows
            // already in trans_date order, so the ORDER BY DESC becomes a
            // backward index scan instead of a sort over the whole day's
            // traffic. Reversed, as (trans_date, vehicle_id), the vehicle filter
            // would degrade to a recheck on every row in the window — which is
            // what happens today.
            //
            // Makes the existing single-column `transactions_vehicle_id_index`
            // (2024_11_12) prefix-redundant. Left in place — see class docblock.
            //
            // Deliberately two columns, NOT (vehicle_id, trans_date, amount): a
            // covering index for the SUM(amount) paths only yields an index-only
            // scan when the heap pages are all-visible, and these queries read
            // the newest 30 days — the most recently written pages, the least
            // likely to be marked all-visible on an insert-heavy table. It would
            // cost ~8 bytes/row plus per-insert maintenance across 20M rows and
            // still fall back to heap fetches in exactly the window it was built
            // for.
            'transactions_vehicle_id_trans_date_index' => ['vehicle_id', 'trans_date'],
        ],

        'cashes' => [
            // Serves CashAPIController::getTransactions —
            //   WHERE trans_date BETWEEN :day AND :day+1
            //     AND vehicle_id IN (...)
            //   ORDER BY trans_date DESC LIMIT 20 OFFSET n
            //
            // Same shape and same column-order reasoning as `transactions`
            // above: vehicle_id equality first, trans_date as the trailing
            // range/sort column.
            //
            // Makes the existing `cashes_vehicle_id_index` (2024_11_12)
            // prefix-redundant.
            'cashes_vehicle_id_trans_date_index' => ['vehicle_id', 'trans_date'],
        ],

        'summaries' => [
            // This one is on the WRITE path of every settled payment. Four live
            // call sites all issue the same equality/equality lookup before
            // saving, once per incoming M-Pesa confirmation:
            //   C2bPaymentRecorder.php:110        WHERE vehicle_id = ? AND trans_date = ?
            //   APIs/server.php:72                WHERE vehicle_id = ? AND trans_date = ?
            //   CoopRestPaymentsController.php:94 WHERE vehicle_id = ? AND trans_date = ?
            //   CopyMpesa.php:109                 WHERE vehicle_id = ? AND trans_date = ?
            // Today each resolves via one of the two single-column indexes plus
            // a recheck on the other column.
            //
            // (GenerateVehicleSummaries.php:76 issues this same lookup under
            // lockForUpdate, but it sits inside the `/* ... */` block spanning
            // lines 69-99 — dead code, so it is not counted above.)
            //
            // Column order: BOTH predicates are equality at those four sites, so
            // order is free for them. vehicle_id leads because the remaining
            // readers have trans_date as a bare range or equality with NO vehicle
            // filter — SummariesAPIController (WHERE trans_date BETWEEN ... GROUP
            // BY summaries.vehicle_id) and GenerateVehicleSummaries.php:50
            // (WHERE trans_date = ?) — and the existing `summaries_trans_date_index`
            // already serves both. Leading with vehicle_id therefore adds
            // coverage instead of duplicating what is already there.
            //
            // NOT declared UNIQUE even though one row per (vehicle, date) is
            // clearly the intent: GenerateVehicleSummaries' live loop (lines
            // 51-64) reassigns vehicle_id on a single `$summary` object inside
            // its inner foreach, so historical data cannot be trusted to satisfy
            // that constraint and a UNIQUE build would abort partway.
            //
            // Makes the existing `summaries_vehicle_id_index` prefix-redundant.
            'summaries_vehicle_id_trans_date_index' => ['vehicle_id', 'trans_date'],
        ],

        'vehicle_expense_and_fees' => [
            // This table had NO index at all beyond its primary key —
            // `foreignIdFor()` in the 2024_03_11 migration creates a plain
            // unsignedBigInteger column, not an index and not a constraint. Every
            // read of it was a sequential scan.
            //
            // Serves ExpenseAndFeesAPIController::index —
            //   WHERE trans_date BETWEEN :day AND :day+1
            //     AND vehicle_id IN (:the caller's vehicle_users rows)
            //
            // Column order: vehicle_id is the IN predicate, trans_date the range.
            //
            // No separate index on expense_fee_id: it is only ever an optional
            // extra filter layered on top of the range above, there is no FK
            // constraint needing one for delete checks, and the table is bounded
            // by vehicles x days x fee types.
            'vehicle_expense_and_fees_vehicle_id_trans_date_index' => ['vehicle_id', 'trans_date'],
        ],

        'loyalty_transactions' => [
            // Serves LoyaltyOverviewController::index, which runs this TWICE per
            // program row (points issued, then points redeemed):
            //   WHERE sacco_id = ? AND type = ? AND created_at >= now()-30d
            //     => SUM(value)
            // and LoyaltyRedemptionObserver::redemptions() —
            //   WHERE sacco_id = ? AND type = 'redeemed'
            //
            // Column order: sacco_id and type are equality, created_at is the
            // range — both equality columns first so the 30-day window stays one
            // contiguous run at the end of the index.
            //
            // Nothing existing covers this. The table's `(user_id, sacco_id)`
            // index is user-leading and unusable for a sacco-first lookup, and
            // sacco_id has no index of its own: `foreignIdFor()->constrained()`
            // creates the FK constraint, but PostgreSQL — unlike MySQL — does not
            // auto-create a supporting index for one. Nothing is made redundant
            // by this index.
            'loyalty_transactions_sacco_id_type_created_at_index' => ['sacco_id', 'type', 'created_at'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                $this->addIndex($table, $name, $columns);
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                $this->dropIndexIfExists($table, $name);
            }
        }
    }

    /**
     * Create $name on $table without blocking writes.
     *
     * PostgreSQL: `CREATE INDEX CONCURRENTLY IF NOT EXISTS`, preceded by the
     * INVALID-namesake sweep described in the class docblock.
     * Everything else: the portable Schema-builder path — same name, same
     * columns, same order.
     */
    private function addIndex(string $table, string $name, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            if (Schema::hasIndex($table, $name)) {
                return;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($columns, $name): void {
                $blueprint->index($columns, $name);
            });

            return;
        }

        $this->dropIfInvalid($name);

        DB::unprepared(sprintf(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (%s)',
            $this->quote($name),
            $this->quote($table),
            implode(', ', array_map($this->quote(...), $columns)),
        ));
    }

    /**
     * Remove $name, undoing exactly what addIndex() created.
     *
     * `DROP INDEX CONCURRENTLY` also cannot run inside a transaction, which is
     * the second reason `$withinTransaction = false` must stay.
     */
    private function dropIndexIfExists(string $table, string $name): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP INDEX CONCURRENTLY IF EXISTS '.$this->quote($name));

            return;
        }

        if (! Schema::hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name): void {
            $blueprint->dropIndex($name);
        });
    }

    /**
     * Drop $name if a previous failed CONCURRENTLY build left it INVALID.
     *
     * Without this, `IF NOT EXISTS` — which matches on name only — would skip
     * the broken index forever: never used for reads, still maintained on every
     * write. PostgreSQL-only; called only from the pgsql branch.
     */
    private function dropIfInvalid(string $name): void
    {
        $invalid = DB::select(
            'select 1
               from pg_index i
               join pg_class c on c.oid = i.indexrelid
               join pg_namespace n on n.oid = c.relnamespace
              where n.nspname = current_schema()
                and c.relname = ?
                and i.indisvalid = false',
            [$name],
        );

        if ($invalid !== []) {
            DB::unprepared('DROP INDEX CONCURRENTLY IF EXISTS '.$this->quote($name));
        }
    }

    /**
     * Quote a PostgreSQL identifier. Every name here is lower-case ASCII, so
     * this is belt-and-braces rather than strictly necessary — but these strings
     * are interpolated into DDL, so they are quoted and escaped on principle.
     */
    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
};
