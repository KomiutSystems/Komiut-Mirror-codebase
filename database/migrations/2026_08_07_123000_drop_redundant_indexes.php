<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reclaim write throughput: drop single-column indexes that a WIDER existing
 * index already covers, and index the one table that had none at all.
 *
 * WHY THIS IS SAFE TO DO AT ALL. A btree index on (a) answers nothing that an
 * index on (a, b) cannot: PostgreSQL can start a scan from any LEADING PREFIX of
 * a composite key. The narrow index is therefore dead weight for reads, while
 * every INSERT and every UPDATE of the column still has to maintain it. On the
 * 20M-row `transactions` table taking live Daraja C2B webhooks that maintenance
 * is on the customer-payment path, so removing it is a throughput win where it
 * matters most.
 *
 * The wider index is marginally larger per entry, so a bare `a = ?` probe reads
 * a few more pages than it would from the narrow index. That is a rounding error
 * against a per-INSERT write on a table this size.
 *
 * WHY EVERY DROP IS GUARDED AT RUNTIME, not just verified once by hand. This
 * project has NO database backups — only ad-hoc dumps — so a wrong drop is not
 * recoverable from a snapshot; `down()` is the entire safety net. Each entry
 * therefore names the covering index, and `up()` refuses to drop unless, in the
 * database it is actually running against:
 *
 *   1. the table exists;
 *   2. the narrow index exists (otherwise nothing to do);
 *   3. the COVERING index exists right now AND IS VALID — so a database that
 *      skipped one of the 2026_08_07_12xx00 add-migrations keeps its narrow
 *      index. The validity half matters: a `CREATE INDEX CONCURRENTLY` that
 *      failed leaves the index in the catalogue with `indisvalid = false`, where
 *      `pg_indexes` and `Schema::hasIndex()` both still report it as present but
 *      the PLANNER WILL NOT USE IT. Trusting mere existence would let this
 *      sequence happen: 120000 fails partway, someone force-marks it as run to
 *      unblock a deploy, this migration then sees a covering index that is not
 *      really there and drops `transactions_vehicle_id_index` — leaving a 20M-row
 *      table with no usable index on `vehicle_id` on the webhook write path;
 *   4. the narrow index backs NO unique or primary-key constraint, checked in
 *      `pg_constraint` (a constraint index cannot be dropped without dropping
 *      the constraint, and doing so would remove a data-integrity guarantee, not
 *      just an access path).
 *
 * Nothing here touches a foreign key. FK enforcement needs an index on the
 * REFERENCED side (always a primary key or unique here); the referencing-side
 * indexes below are pure access paths. Verified in `pg_constraint`: not one of
 * these names appears as any constraint's `conindid`, anywhere in the schema.
 *
 * `down()` recreates every dropped index with the same name, columns, order and
 * quoting the original migrations produced, so the change is fully reversible.
 *
 * TWO CANDIDATES THAT ARE NOT PREFIXES BUT DUPLICATES: `mpesas("TransID")` and
 * `cashes(trans_id)` each have a plain index AND a unique index on the SAME
 * single column. That is stronger than prefix redundancy — the unique index
 * serves every query the plain one could — and the plain one is the one dropped.
 * The UNIQUE stays, so the dedupe guarantee on replayed M-Pesa callbacks is
 * untouched.
 *
 * ALSO IN THIS FILE (additive): `firebase_tokens` had ZERO indexes beyond its
 * primary key, while `where firebase_token = ?` runs on every sign-in and
 * `where user_id = ?` on every push fan-out. Both are added here. They are plain
 * indexes, deliberately NOT unique: a token can legitimately be re-registered or
 * move between users, so a unique build could abort on populated data.
 *
 * LOCKING — WHY `$withinTransaction = false` IS HERE. DO NOT REMOVE IT.
 *
 * `DROP INDEX CONCURRENTLY` takes no lock that blocks reads or writes, and
 * `CREATE INDEX CONCURRENTLY` is what keeps the two `firebase_tokens` builds off
 * the sign-in path. NEITHER can run inside a transaction block, and Laravel
 * wraps each migration in one on PostgreSQL — `$withinTransaction = false`
 * disables that wrapper. Delete it and every statement here fails with
 * "... cannot run inside a transaction block".
 *
 * Because the migration is not transactional, a mid-way failure leaves it
 * partially applied and unrecorded. Both directions are idempotent so the re-run
 * is safe: `IF EXISTS` / `IF NOT EXISTS` on every statement, the guards above
 * re-checked per index, and `dropIfInvalid()` clearing any namesake a previously
 * FAILED concurrent build left with `pg_index.indisvalid = false` (such an index
 * is never used by the planner but is still maintained on every write, and
 * `IF NOT EXISTS` matches on name alone, so it would otherwise be skipped over
 * forever). An index another session is building CONCURRENTLY right now is also
 * `indisvalid = false`, so do not run two copies of this migration at once.
 *
 * Non-PostgreSQL drivers (sqlite, MySQL) take the portable Schema-builder path
 * with the same guards; the index names and columns are identical either way.
 */
return new class extends Migration
{
    /**
     * Disables Laravel's per-migration transaction. Required by
     * CREATE/DROP INDEX CONCURRENTLY. See the class docblock before touching.
     */
    public $withinTransaction = false;

    /**
     * The redundant indexes, each with the wider index that must exist for the
     * drop to be allowed and the exact column list `down()` recreates it from.
     *
     * @var array<int, array{table: string, index: string, columns: array<int, string>, covered_by: string}>
     */
    private const REDUNDANT = [
        // (trans_date) is the leading prefix of (trans_date, created_at), which
        // create_transactions_table has carried since 2023. The narrow one was
        // added later, in 2024_11_12, apparently without noticing.
        [
            'table' => 'transactions',
            'index' => 'transactions_trans_date_index',
            'columns' => ['trans_date'],
            'covered_by' => 'transactions_trans_date_created_at_index',
        ],

        // (vehicle_id) is the leading prefix of the new (vehicle_id, trans_date)
        // composite from 2026_08_07_120000. This is the expensive one to keep:
        // `transactions` is the 20M-row table on the webhook write path.
        [
            'table' => 'transactions',
            'index' => 'transactions_vehicle_id_index',
            'columns' => ['vehicle_id'],
            'covered_by' => 'transactions_vehicle_id_trans_date_index',
        ],

        // Same prefix relationship on the cash ledger.
        [
            'table' => 'cashes',
            'index' => 'cashes_vehicle_id_index',
            'columns' => ['vehicle_id'],
            'covered_by' => 'cashes_vehicle_id_trans_date_index',
        ],

        // Same again on the per-vehicle daily rollup. `summaries` is written once
        // per settled payment, so this index cost a write on every M-Pesa
        // confirmation for nothing.
        [
            'table' => 'summaries',
            'index' => 'summaries_vehicle_id_index',
            'columns' => ['vehicle_id'],
            'covered_by' => 'summaries_vehicle_id_trans_date_index',
        ],

        // DUPLICATE, not a prefix: create_mpesas_table declared TransID unique
        // AND 2024_11_12 added a plain index on the same single column. The
        // unique index (mpesas_transid_unique, which backs the UNIQUE constraint
        // that dedupes replayed Daraja callbacks) serves every lookup the plain
        // one could. Note the quoted mixed-case column name — preserved exactly
        // in down().
        [
            'table' => 'mpesas',
            'index' => 'mpesas_transid_index',
            'columns' => ['TransID'],
            'covered_by' => 'mpesas_transid_unique',
        ],

        // Identical situation on `cashes.trans_id`: unique from
        // create_cashes_table, plain index from 2024_11_12.
        [
            'table' => 'cashes',
            'index' => 'cashes_trans_id_index',
            'columns' => ['trans_id'],
            'covered_by' => 'cashes_trans_id_unique',
        ],

        // (sacco_id, route_id) is the leading prefix of the 4-column
        // route_fares_pair_unique (sacco_id, route_id, from_place_id,
        // to_place_id). The resolver's "every fare for this (sacco, route)" read
        // is served by that unique index's first two columns.
        [
            'table' => 'route_fares',
            'index' => 'route_fares_sacco_id_route_id_index',
            'columns' => ['sacco_id', 'route_id'],
            'covered_by' => 'route_fares_pair_unique',
        ],

        // (sacco_id) is the leading prefix of the unique (sacco_id,
        // period_start) that makes invoice generation idempotent.
        [
            'table' => 'invoices',
            'index' => 'invoices_sacco_id_index',
            'columns' => ['sacco_id'],
            'covered_by' => 'invoices_sacco_id_period_start_unique',
        ],

        // (place_id) is the leading prefix of the new (place_id, route_id,
        // distance) from 2026_08_07_121000, which was built for the dropoff side
        // of the route-segment self-join.
        [
            'table' => 'route_stages',
            'index' => 'route_stages_place_id_index',
            'columns' => ['place_id'],
            'covered_by' => 'route_stages_place_route_distance_index',
        ],

        // (action) is the leading prefix of the new (action, created_at) from
        // 2026_08_07_122000. NOTE this is NOT the same index as
        // audit_logs_action_prefix_index — that one uses varchar_pattern_ops for
        // `LIKE 'foo%'` and is NOT covered by a default-opclass composite, so it
        // stays. `audit_logs` is append-only and written before every money/PII
        // event, so one less index per write is directly on that path.
        [
            'table' => 'audit_logs',
            'index' => 'audit_logs_action_index',
            'columns' => ['action'],
            'covered_by' => 'audit_logs_action_created_at_index',
        ],

        // (brand) is the leading prefix of the unique (brand, key). `brand` is
        // nullable, but btree indexes NULLs, so `brand IS NULL` lookups still
        // start from the wider index's leading column.
        [
            'table' => 'platform_thresholds',
            'index' => 'platform_thresholds_brand_index',
            'columns' => ['brand'],
            'covered_by' => 'platform_thresholds_brand_key_unique',
        ],
    ];

    /**
     * Additive: the indexes `firebase_tokens` should have had all along.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private const ADDITIONS = [
        'firebase_tokens' => [
            // DeviceController looks the row up by token on every registration /
            // sign-in: `where firebase_token = ?`. Plain, not unique — see the
            // class docblock.
            'firebase_tokens_firebase_token_index' => ['firebase_token'],

            // Push fan-out reads every device for a user: `where user_id = ?`.
            // `user_id` came from foreignIdFor() without ->constrained(), so
            // PostgreSQL created no index for it.
            'firebase_tokens_user_id_index' => ['user_id'],
        ],
    ];

    public function up(): void
    {
        foreach (self::ADDITIONS as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                $this->addIndex($table, $name, $columns);
            }
        }

        foreach (self::REDUNDANT as $entry) {
            $this->dropRedundant($entry['table'], $entry['index'], $entry['covered_by']);
        }
    }

    public function down(): void
    {
        foreach (self::REDUNDANT as $entry) {
            $this->addIndex($entry['table'], $entry['index'], $entry['columns']);
        }

        foreach (self::ADDITIONS as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                $this->dropIndexIfExists($table, $name);
            }
        }
    }

    /**
     * Drop $index only if every safety condition in the class docblock holds.
     * Any failure is a silent skip: keeping a redundant index costs write
     * throughput, dropping a needed one is an outage.
     */
    private function dropRedundant(string $table, string $index, string $coveredBy): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        // Already gone (or this is a re-run) — nothing to do.
        if (! Schema::hasIndex($table, $index)) {
            return;
        }

        // The wider index must exist HERE AND NOW — and be VALID, not merely
        // catalogued. See condition 3 in the class docblock.
        if (! $this->hasUsableIndex($table, $coveredBy)) {
            return;
        }

        // Never drop an index that backs a UNIQUE or PRIMARY KEY constraint.
        if ($this->backsConstraint($table, $index)) {
            return;
        }

        $this->dropIndexIfExists($table, $index);
    }

    /**
     * Whether $index exists AND the planner can actually use it.
     *
     * `Schema::hasIndex()` (and `pg_indexes`) report an index left behind by a
     * failed `CREATE INDEX CONCURRENTLY` as present even though it is dead to the
     * planner. Only `pg_index.indisvalid` / `indisready` distinguish the two, so
     * the covering-index check has to read them directly. Other drivers have no
     * such state and fall back to plain existence.
     */
    private function hasUsableIndex(string $table, string $name): bool
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return Schema::hasIndex($table, $name);
        }

        return DB::select(
            'select 1
               from pg_index i
               join pg_class c on c.oid = i.indexrelid
               join pg_namespace n on n.oid = c.relnamespace
              where n.nspname = current_schema()
                and c.relname = ?
                and i.indisvalid
                and i.indisready',
            [$name],
        ) !== [];
    }

    /**
     * Whether $index exists only as a constraint's supporting index.
     *
     * On PostgreSQL this reads `pg_constraint`, which is the authority —
     * `pg_indexes` cannot tell a constraint index from a plain one. Other
     * drivers fall back to the Schema builder's unique/primary flags.
     */
    private function backsConstraint(string $table, string $index): bool
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return Schema::hasIndex($table, $index, 'unique')
                || Schema::hasIndex($table, $index, 'primary');
        }

        return DB::select(
            'select 1
               from pg_constraint c
               join pg_class i on i.oid = c.conindid
               join pg_namespace n on n.oid = i.relnamespace
              where n.nspname = current_schema()
                and i.relname = ?',
            [$index],
        ) !== [];
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
     * Remove $name. `DROP INDEX CONCURRENTLY` also cannot run inside a
     * transaction, which is the second reason `$withinTransaction = false` must
     * stay.
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
     * Quote a PostgreSQL identifier — required here, not cosmetic:
     * `mpesas_transid_index` is on the mixed-case column "TransID", which
     * PostgreSQL folds to `transid` if left bare.
     */
    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
};
