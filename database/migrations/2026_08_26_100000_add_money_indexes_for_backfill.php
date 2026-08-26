<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money-table indexes for the live load AND the imminent ~6.3M-row backfill.
 *
 * THREE indexes, and only three. Every index is a tax on every INSERT, and the
 * backfill is about to do 6.3M of them into `mpesas` and 6.3M into
 * `transactions`. So the bar for adding one here is not "this might help some
 * day" — it is "a query that runs today reads more than it should, and the
 * backfill makes that six times worse". Everything that did not clear that bar
 * is written up under NOT ADDED, WITH REASONS at the bottom of this docblock,
 * along with the indexes that should be DROPPED (deliberately not done here).
 *
 * Measured on the live Frankfurt database on 2026-08-26 before this migration:
 * `mpesas` 1,319,882 rows / 463 MB, `transactions` 1,319,838 rows / 298 MB,
 * `mpesa_logs` 7,838 rows. Row counts and plans quoted below are from that read,
 * taken with `EXPLAIN` (no ANALYZE) and catalogue queries only — nothing in this
 * work wrote to production.
 *
 * ---------------------------------------------------------------------------
 * LOCKING — WHY `$withinTransaction = false` IS HERE. DO NOT REMOVE IT.
 * ---------------------------------------------------------------------------
 *
 * A plain `CREATE INDEX` takes an ACCESS EXCLUSIVE-grade lock on the table for
 * the whole build: every INSERT blocks until it finishes. On the legacy stack an
 * index build did exactly that and took the system down. `mpesas` is taking live
 * Daraja C2B confirmations right now — at 463 MB and about to become ~2.7 GB,
 * a blocking build there is minutes of rejected customer payments, i.e. an
 * outage. So EVERY statement in this file, in BOTH directions, is
 * `CREATE INDEX CONCURRENTLY` / `DROP INDEX CONCURRENTLY`, which take no lock
 * that blocks readers or writers.
 *
 * `CONCURRENTLY` cannot run inside a transaction block, and Laravel wraps each
 * migration in one on PostgreSQL. `public $withinTransaction = false` is the
 * property that disables that wrapper — verified against this repo's framework
 * source, not assumed: `Illuminate\Database\Migrations\Migration::$withinTransaction`
 * (declared `true`) is read at `Migrations\Migrator.php:449`, where the migration
 * body is wrapped in `$connection->transaction()` only when the grammar supports
 * schema transactions AND that property is true. Delete it and every statement
 * below fails with "CREATE INDEX CONCURRENTLY cannot run inside a transaction
 * block".
 *
 * The price of leaving the transaction is that a mid-way failure is no longer
 * rolled back: some indexes exist and the migration is not recorded as run. Two
 * things make the re-run safe:
 *
 *   - `IF NOT EXISTS` / `IF EXISTS` on every statement.
 *   - An INVALID-index sweep. A `CONCURRENTLY` build that fails (deadlock,
 *     cancelled statement, a `statement_timeout`, someone killing the deploy)
 *     leaves the index behind with `pg_index.indisvalid = false`. That index is
 *     the worst of both worlds: `pg_indexes` and `Schema::hasIndex()` both report
 *     it as PRESENT, but the planner will not use it — while every INSERT still
 *     maintains it. Because `IF NOT EXISTS` matches on NAME alone, a naive re-run
 *     skips straight over the broken index and leaves the trap in place forever.
 *     `dropIfInvalid()` therefore drops any invalid namesake BEFORE creating, so
 *     a re-run genuinely repairs rather than papering over.
 *     Caveat: an index another session is building CONCURRENTLY *right now* is
 *     also `indisvalid = false`, so do not run two copies of this migration at
 *     the same time. (Checked on 2026-08-26: no invalid index exists anywhere in
 *     the live schema today, so the first run starts from a clean slate.)
 *
 * A failed concurrent build also does NOT abort the rest of the migration on
 * PostgreSQL, because there is no surrounding transaction to poison — each
 * statement stands alone. That is the intended behaviour here: getting two of
 * three indexes built is strictly better than getting none, and the re-run
 * finishes the job.
 *
 * ---------------------------------------------------------------------------
 * NOT ADDED, WITH REASONS
 * ---------------------------------------------------------------------------
 *
 * - `mpesas("TransID")`, `mpesas("TransTime")`, `transactions(mpesa_id)`,
 *   `transactions(vehicle_id, trans_date)`, `transactions(trans_date, created_at)`,
 *   `summaries(vehicle_id, trans_date)`: all already present and all measurably
 *   used (`transactions_mpesa_id_index` alone has 4.07M scans since the cluster
 *   started). The reasoning behind the composites is in 2026_08_07_120000 and is
 *   not repeated or contradicted here. The hot live reads — a day page of
 *   `mpesas` (`Index Scan Backward using mpesas_transtime_index`), the
 *   `TransID` dedupe lookup on every confirmation, the per-vehicle transaction
 *   list — are already index scans and stay index scans at 7.6M rows, because a
 *   btree costs a page or two more per probe when a table grows sixfold, not six
 *   times more.
 *
 * - `mpesa_stk_callbacks`: the `payments:reconcile` poll every two minutes plans
 *   as a Seq Scan, but the table holds ZERO rows on this stack (13,321 sequential
 *   scans, 0 tuples read) — STK is not carrying traffic here yet. An index on an
 *   empty table serves nothing. WHEN STK GOES LIVE, the index that poll wants is
 *   `mpesa_stk_callbacks (created_at) WHERE processed_at IS NULL` — a queue-table
 *   partial index holding only the unprocessed backlog. That predicate is a bare
 *   `IS NULL`, so the planner can prove it from the query with no dependence on
 *   parameter values (see the note on the settlement index below).
 *
 * - `qrcode_payments`, `mpesa_qrcode_payments`, `cashes`: zero rows each; their
 *   writers (`app:copy-qrcode-payments`, `app:copy-cash`) are commented out in
 *   the scheduler. Nothing to serve.
 *
 * - `transactions(summarized)`: `summarized` is written by four call sites and
 *   READ by none — `GenerateVehicleSummaries` recomputes from a `trans_date`
 *   window, it does not hunt for unsummarized rows. Indexing it would cost 6.3M
 *   backfill writes for a column no query filters on. (Worth knowing for the
 *   backfill: the same command anchors on
 *   `Transaction::orderBy('trans_date','DESC')->first()`, and every backfilled
 *   row is OLDER than today's traffic, so importing 6.3M rows does not drag the
 *   five-minute summariser back over history.)
 *
 * - A covering `(vehicle_id, trans_date, amount)` on `transactions`: rejected for
 *   the same reason 2026_08_07_120000 rejected it — the SUM paths read the newest
 *   days, which are the least likely heap pages to be all-visible, so the
 *   index-only scan degrades to heap fetches in exactly the window it was built
 *   for. The backfill only strengthens that: 6.3M freshly inserted pages are all
 *   not-all-visible until autovacuum catches up.
 *
 * ---------------------------------------------------------------------------
 * SHOULD BE DROPPED — RECOMMENDED, DELIBERATELY NOT DONE HERE
 * ---------------------------------------------------------------------------
 *
 * This migration is ADDITIVE ONLY. This project has no database backups (see
 * 2026_08_07_123000), so a drop is its own reviewed change with its own guards,
 * not a footnote on an add. Each of these costs real backfill throughput and
 * should be a decision made before the 6.3M rows land:
 *
 * 1. THE FOUR GIN TRIGRAM INDEXES ON `mpesas` — the biggest single lever on the
 *    backfill. `mpesas_transid_trgm_index` (53 MB), `mpesas_firstname_trgm_index`
 *    (28 MB), `mpesas_middlename_trgm_index` (2.5 MB),
 *    `mpesas_lastname_trgm_index` (2.4 MB), added by 2026_08_16_090000.
 *
 *    A GIN insert is not one index entry, it is one entry PER TRIGRAM: a 10-char
 *    `TransID` produces ~12. Four GIN indexes across four columns therefore turn
 *    each of the 6.3M backfilled rows into tens of index insertions. `fastupdate`
 *    (on by default) buffers them in a pending list, but `gin_pending_list_limit`
 *    on this cluster is only 4 MB, so a bulk load flushes and merges that list
 *    continuously — and each merge is I/O against a 413 MB `shared_buffers`.
 *    Expect these four indexes to dominate the wall-clock time of the import and
 *    to finish at roughly 500 MB combined.
 *
 *    The evidence that they can be spared: since the cluster started on
 *    2026-08-07, all four have `idx_scan = 0`. Not one planner scan. They serve
 *    the dashboard search box, which nobody has used in that window.
 *
 *    RECOMMENDATION: `DROP INDEX CONCURRENTLY` all four before the backfill, then
 *    rebuild each with `CREATE INDEX CONCURRENTLY` afterwards (raise
 *    `maintenance_work_mem` above its current 64 MB for the rebuild). Rebuilding
 *    once over 7.6M rows is far cheaper than maintaining four GIN indexes across
 *    6.3M individual inserts.
 *
 *    THE RISK, STATED PLAINLY: while they are dropped, the search box falls back
 *    to `ILIKE '%needle%'` over `mpesas` — which is the exact query shape that
 *    took komiut.com down for ~6 hours on 2026-08-07, and it would now be doing
 *    it over 7.6M rows instead of 1.3M. So the drop/backfill/rebuild has to be a
 *    window in which the dashboard search is known to be unused, and the rebuild
 *    must be confirmed VALID (`pg_index.indisvalid`) before that window closes.
 *    If nobody will own that window, keep them and accept a slower import.
 *
 * 2. `mpesas_firstname_middlename_lastname_index` (14 MB, `idx_scan = 0`). Its
 *    own successor migration explains why it is dead: the only reader of those
 *    columns is a leading-wildcard `ILIKE`, which a btree cannot serve. It is a
 *    pure write cost that the backfill grows to ~80 MB. Unlike the GIN indexes it
 *    has no rebuild-afterwards clause — it should simply go.
 *
 * 3. `transactions_cash_id_index` (8.3 MB). `cashes` is empty on this stack and
 *    `count(cash_id)` over all 1,319,838 transactions is ZERO — every entry in
 *    that index is a NULL. The backfill adds 6.3M more NULL entries. If the
 *    column is kept, the index should be
 *    `transactions (cash_id) WHERE cash_id IS NOT NULL`: a `where cash_id = ?`
 *    lookup uses a strict operator, so the planner proves `IS NOT NULL` from the
 *    query without needing to know the parameter's value.
 *
 * 4. `mpesas_mpesa_setting_id_index` (9 MB). Only 7,830 of 1,319,882 rows carry a
 *    setting id (0.6%) — and `ImportLegacyMoney::COLUMNS` does not include
 *    `mpesa_setting_id`, so ALL 6.3M backfilled rows will be NULL there. Same
 *    treatment as (3): make it `WHERE mpesa_setting_id IS NOT NULL` and it goes
 *    from ~7.6M entries to ~8k while still serving the per-till cutover report.
 *
 * ---------------------------------------------------------------------------
 * PORTABILITY
 * ---------------------------------------------------------------------------
 *
 * Non-PostgreSQL drivers keep the portable Schema-builder path for the two plain
 * indexes — same names, same columns. The partial index is skipped there
 * entirely rather than silently created as a full index under the same name: an
 * index that differs in shape between environments is worse than one that is
 * honestly absent.
 */
return new class extends Migration
{
    /**
     * Disables Laravel's per-migration transaction. Required by
     * CREATE/DROP INDEX CONCURRENTLY. See the class docblock before touching.
     */
    public $withinTransaction = false;

    /**
     * Plain btree indexes: table => [index name => columns].
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private const INDEXES = [
        'mpesa_logs' => [
            // REQUIRED — the reconciliation check needs both of this table's
            // indexes, and the table has carried NOTHING but its primary key
            // since 2023_12_29.
            //
            // `mpesa_logs` is the raw-payload trace written BEFORE any parsing,
            // by all four ingestion paths (C2bConfirmationController, the NCBA
            // and Co-op REST endpoints, and APIs/server.php). It is the only
            // record of a payment whose parsing failed, and — per
            // C2bConfirmationController's docblock — it is what proves that
            // traffic has actually started arriving here as tills are
            // re-registered one at a time.
            //
            // "Did TransID X reach us at all?" is the first question asked about
            // any payment a SACCO says is missing, and the answer has to be
            // authoritative even when nothing else about that payment exists.
            // Today it plans as `Seq Scan on mpesa_logs, Filter: trans_id = ...`
            // over the whole table.
            //
            // The table is small NOW and that is exactly why this is cheap to do
            // NOW. Every row in it arrived after 2026-08-25 11:59 — the first
            // till re-registered against this host — and it grew from 7,838 to
            // 8,179 rows DURING the half hour this migration was being written.
            // At that rate it passes the size where a sequential scan stops being
            // free within days, and it climbs again with every till that moves
            // across. The build is instantaneous today.
            //
            // Not UNIQUE: `trans_id` is nullable, a payload we could not parse
            // writes an empty string, and Safaricom retries deliver the same
            // TransID more than once — 7,838 rows already held only 7,835
            // distinct values. A unique build would abort on live data, and
            // uniqueness is not what this table is for; `mpesas_transid_unique`
            // is where the dedupe guarantee lives.
            'mpesa_logs_trans_id_index' => ['trans_id'],

            // REQUIRED — the other half of the same check, and a different
            // question: not "did this payment arrive" but "is anything arriving".
            //
            // The per-till cutover (see 2026_08_26_090000 and the rejected DNS
            // flip) is verified by asking whether confirmations have landed since
            // a till was re-registered — `where created_at >= ?`, counted or
            // grouped. Today that is a Seq Scan too. It is also the column any
            // future retention prune would work from; `logs:prune` already trims
            // `request_logs` and `application_logs` by `created_at` and this
            // table has no such trim yet, which is the other reason it will not
            // stay small.
            //
            // Separate index rather than a `(trans_id, created_at)` composite:
            // the two questions share no predicate. One is an equality probe on
            // trans_id with no time bound; the other is a range on created_at
            // across all trans_ids. A composite would serve the first and be
            // useless for the second, because created_at would not be a leading
            // column.
            'mpesa_logs_created_at_index' => ['created_at'],
        ],
    ];

    /**
     * The one partial index. PostgreSQL only — see PORTABILITY above.
     *
     * @var array<string, array{table: string, columns: array<int, string>, where: string}>
     */
    private const PARTIAL_INDEXES = [
        // Serves `app:attribute-coop-settlements`. Its hourly schedule entry has
        // since been commented out for the duration of the backfill (see
        // Console/Kernel.php for why, and for what has to be true before it goes
        // back), so the "every hour" arithmetic below is now the cost of a
        // re-enabled schedule rather than of today. The index still earns its
        // keep in the meantime: the reviewed one-off run that MUST happen over
        // the imported history is the same query over the same ~2.7 GB, and it
        // is a run a person waits on.
        //
        //   Mpesa::whereIn('TransactionType', SETTLEMENT_TYPES)
        //        ->whereNotExists(transactions where mpesa_id = mpesas.id)
        //        ->orderBy('id')->get()
        //
        // `TransactionType` has no index, so this plans today as:
        //
        //   Nested Loop Anti Join
        //     -> Parallel Seq Scan on mpesas  (cost=0.00..37378.57)
        //          Filter: "TransactionType" = ANY ('{Organization To
        //                  Organization Transfer,OD Payment Transfer}')
        //     -> Index Only Scan using transactions_mpesa_id_index
        //
        // That is a full read of `mpesas` every hour. Survivable at 463 MB. After
        // the backfill it is a full read of ~2.7 GB every hour, on a cluster
        // whose `shared_buffers` is 413 MB — so the hourly maintenance job would
        // evict the entire buffer cache, six times over, out from under the live
        // C2B write path. That is the cost this index removes, and it is a cost
        // the backfill CREATES; it is not a pre-existing complaint.
        //
        // WHY PARTIAL, AND WHY IT IS ALMOST FREE. The two settlement types are
        // 9,849 of 1,319,882 rows — 0.75%. 96.6% of the table is
        // 'Customer Merchant Payment'. A full index on `TransactionType` would
        // be ~7.6M entries and ~300 MB after the backfill, and would take a write
        // on every live confirmation, to answer a question about 0.75% of rows. A
        // partial index only stores — and only maintains — the rows matching its
        // predicate. The other 6.3M backfilled rows pay one predicate evaluation
        // and no index write at all.
        //
        // WHY THE INDEXED COLUMN IS `id` AND NOT `TransactionType`. Inside the
        // predicate `TransactionType` is near-constant, so indexing it stores
        // nothing useful. `id` is 8 bytes, and the query's `orderBy('id')` is
        // then satisfied by the index order — no sort node over the result.
        //
        // THE ONE CAVEAT, STATED SO IT CAN BE VERIFIED. A partial index is only
        // used when PostgreSQL can PROVE the query's WHERE implies the index
        // predicate. Laravel emits `in (?, ?)` with bindings, and proof happens
        // after constant-folding — which substitutes bound parameters only under
        // a CUSTOM plan. Every PHP request/command prepares the statement fresh
        // and executes it once, so it gets a custom plan and the proof succeeds.
        // If that ever stopped holding, the planner would fall back to the Seq
        // Scan it already does today, so the downside is the status quo, and the
        // wasted cost is ~10k index entries. Confirm after deploy with:
        //
        //   EXPLAIN SELECT * FROM mpesas
        //    WHERE "TransactionType" IN ('Organization To Organization Transfer',
        //                                'OD Payment Transfer')
        //      AND NOT EXISTS (SELECT 1 FROM transactions
        //                       WHERE transactions.mpesa_id = mpesas.id)
        //    ORDER BY id;
        //
        // KEEP THE PREDICATE IN SYNC with
        // AttributeCoopSettlements::SETTLEMENT_TYPES. If a third settlement type
        // is added there and not here, the index silently stops matching and the
        // hourly seq scan comes back. The predicate is spelled out literally
        // rather than built from the constant because an index definition lives
        // in the database, not in PHP — a later edit to the PHP constant must not
        // change what an already-built index means.
        'mpesas_settlement_deposits_index' => [
            'table' => 'mpesas',
            'columns' => ['id'],
            'where' => '"TransactionType" IN (\'Organization To Organization Transfer\', \'OD Payment Transfer\')',
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                $this->addIndex($table, $name, $columns);
            }
        }

        foreach (self::PARTIAL_INDEXES as $name => $spec) {
            $this->addPartialIndex($spec['table'], $name, $spec['columns'], $spec['where']);
        }
    }

    public function down(): void
    {
        foreach (self::PARTIAL_INDEXES as $name => $spec) {
            $this->dropIndexIfExists($spec['table'], $name);
        }

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
     * Same as addIndex(), plus a WHERE predicate.
     *
     * PostgreSQL only: the Schema builder has no portable way to express a
     * partial index, and emitting a FULL index under the same name on another
     * driver would make the two environments disagree about what that name
     * means. Skipping is the honest outcome — see PORTABILITY in the docblock.
     *
     * $where is raw SQL from this file's own constant, never from input.
     */
    private function addPartialIndex(string $table, string $name, array $columns, string $where): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql' || ! Schema::hasTable($table)) {
            return;
        }

        $this->dropIfInvalid($name);

        DB::unprepared(sprintf(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (%s) WHERE %s',
            $this->quote($name),
            $this->quote($table),
            implode(', ', array_map($this->quote(...), $columns)),
            $where,
        ));
    }

    /**
     * Remove $name, undoing exactly what up() created.
     *
     * `DROP INDEX CONCURRENTLY` also cannot run inside a transaction, which is
     * the second reason `$withinTransaction = false` must stay. `IF EXISTS`
     * makes down() safe after a partial up().
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
     * write. Index names are unique per schema in PostgreSQL, so matching on
     * `relname` within `current_schema()` identifies it unambiguously.
     * PostgreSQL-only; called only from the pgsql paths.
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
     * Quote a PostgreSQL identifier. Required, not cosmetic: `mpesas` carries
     * Safaricom's mixed-case column names and PostgreSQL folds an unquoted
     * identifier to lower case, so an unquoted "TransactionType" would ask for a
     * column called `transactiontype`, which does not exist.
     */
    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
};
