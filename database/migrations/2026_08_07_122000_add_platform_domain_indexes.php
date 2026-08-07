<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the PLATFORM / IDENTITY domain: users, saccos, sacco_users,
 * notifications, platform_notifications, audit_logs.
 *
 * Column order follows the real predicates, not a rule of thumb. Two facts about
 * this domain drove every choice below, and both are the opposite of what the
 * operational tables (vehicles/queues/transactions) look like:
 *
 *  1. MOST TABLES HERE ARE NOT BRAND-SCOPED. BrandScope only reaches a model
 *     through App\Models\Concerns\BelongsToBrand, and of this domain only
 *     `saccos` uses it. `users` uses neither BelongsToBrand nor BelongsToSacco
 *     (it has no `brand` column at all — a driver reaches its brand through its
 *     sacco); `notifications` is Laravel's own table; and
 *     platform_notifications / audit_logs / platform_thresholds are DELIBERATELY
 *     cross-brand, because the super admin sits above the brand boundary (see
 *     each model's docblock). So `brand` is a filter column here, not a leading
 *     tenancy predicate, and putting it first would have been wrong.
 *
 *  2. Every consumer of these tables is the /super console, where BrandScope and
 *     SaccoScope are both exempt (User::isSuperAdmin). The predicate that IS
 *     always present on those endpoints is the one each index below leads with.
 *
 * The raw statements are PostgreSQL-only and guarded on the driver: prefix-LIKE
 * support needs a *_pattern_ops opclass (this database collates en_US.utf8, not
 * C, so a default btree cannot serve `LIKE 'foo%'` at all), and partial indexes
 * have no portable Schema-builder equivalent. sqlite/mysql simply keep the
 * portable indexes above them.
 *
 * LOCKING — WHY `$withinTransaction = false` IS HERE. DO NOT REMOVE IT.
 *
 * A plain `CREATE INDEX` takes a SHARE lock on the table: reads continue but
 * every INSERT blocks until the build finishes. `audit_logs`,
 * `platform_notifications` and `notifications` are all append-heavy, and
 * `audit_logs` is written BEFORE every money/PII/privilege event — blocking it
 * blocks the write path it is auditing. So on PostgreSQL every index below,
 * portable and raw alike, is built with `CREATE INDEX CONCURRENTLY`, which takes
 * no blocking lock.
 *
 * `CONCURRENTLY` cannot run inside a transaction block, and Laravel wraps each
 * migration in one on PostgreSQL. `public $withinTransaction = false` is what
 * disables that wrapper. Delete it and every create below fails with
 * "CREATE INDEX CONCURRENTLY cannot run inside a transaction block"; the same
 * holds for `DROP INDEX CONCURRENTLY` in `down()`.
 *
 * The cost of leaving the transaction is that a mid-way failure is not rolled
 * back: some indexes exist and the migration is not recorded. `IF NOT EXISTS`
 * makes the re-run skip what already exists, and `dropIfInvalid()` first removes
 * any namesake a previously FAILED concurrent build left with
 * `pg_index.indisvalid = false` — such an index is never read by the planner but
 * is still maintained on every write, and `IF NOT EXISTS` matches on name alone
 * so it would otherwise be skipped over forever. (An index another session is
 * building CONCURRENTLY right now is also `indisvalid = false`, so do not run
 * two copies of this migration concurrently.)
 *
 * The index definitions themselves are unchanged from the plain-CREATE version:
 * same names, same columns, same order, same opclasses, same partial WHERE
 * clauses. Only the locking behaviour is different.
 */
return new class extends Migration
{
    /**
     * Disables Laravel's per-migration transaction. Required by
     * CREATE/DROP INDEX CONCURRENTLY. See the class docblock before touching.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        // ------------------------------------------------------------------
        // users
        // ------------------------------------------------------------------
        // Serves, verbatim:
        //   User::where('sacco_id', $id)->count()                       (DirectoryController::row, merge())
        //   User::where('sacco_id', $id)->where('type', Driver)->count()(SaccosController::row, SaccoDetailController::show)
        //   User::where('sacco_id', $id)->update(['sacco_id' => ...])   (DirectoryController::merge)
        //   whereHas('sacco', fn ($s) => $s->where('brand', ...))       (Super\Users\UsersController::index)
        //   join saccos on users.sacco_id = saccos.id                   (PlatformDailyDigest)
        //
        // sacco_id first because it is the equality that is always present
        // and by far the most selective (one SACCO out of thousands); `type`
        // second because it is the only other equality layered on top of it.
        // A single index on (sacco_id) would be redundant with this one — the
        // leading column already serves the sacco_id-only counts — so it is
        // deliberately not added.
        //
        // This is the hottest miss in the domain: `sacco_id` was created by
        // foreignIdFor() without ->constrained(), so it carries NO index
        // today, and the two console list endpoints run these counts once per
        // row — 25 sequential scans of `users` per page.
        $this->addIndex('users', 'users_sacco_id_type_index', ['sacco_id', 'type']);

        // ------------------------------------------------------------------
        // sacco_users  (zero indexes today beyond the primary key)
        // ------------------------------------------------------------------
        // Serves:
        //   SaccoUser::where('sacco_id', $id)->whereNull('end_date')->count()
        //     (SaccosController::row + SaccoDetailController::show — again 25x per page)
        //   SaccoUser::where('sacco_id', $request->sacco)…  (SaccoMembersAPIController::getMembers)
        //   the SaccoScope global filter `sacco_users.sacco_id = ?`, which is
        //   the one place in this domain where the task's "sacco_id comes
        //   first" premise really does hold (BelongsToSacco is on this model).
        //
        // sacco_id (equality, highly selective) then end_date, which is only
        // ever tested IS NULL — a boolean-ish discriminator, so it earns the
        // trailing position rather than a leading one.
        $this->addIndex('sacco_users', 'sacco_users_sacco_id_end_date_index', ['sacco_id', 'end_date']);

        // Serves the membership-transfer path in SaccoMembersAPIController::addMember:
        //   SaccoUser::where('user_id', $m)->where('sacco_id', $s)->where('end_date', null)->first()
        //   SaccoUser::where('user_id', $m)->where('id', '<>', $x)->where('end_date', null)->update([...])
        //
        // A separate index from the one above rather than a three-column
        // (sacco_id, user_id, end_date): the closing UPDATE has no sacco_id
        // predicate at all — it deliberately looks across every SACCO to end
        // the member's other memberships — so it can only be driven from
        // user_id. `id <> ?` is an anti-predicate and indexes nothing.
        $this->addIndex('sacco_users', 'sacco_users_user_id_end_date_index', ['user_id', 'end_date']);

        // ------------------------------------------------------------------
        // saccos
        // ------------------------------------------------------------------
        // Serves the two paginated console lists:
        //   DirectoryController::index — where status = 1 [+ claim_status] [+ brand]
        //                                order by created_at desc limit 25
        //   SaccosController::index    — [+ status] [+ brand] [+ claim_status]
        //                                order by created_at desc limit 25
        //
        // status leads because DirectoryController applies it unconditionally
        // and it is the only unconditional equality; created_at trails
        // because the ORDER BY is unconditional too, which lets Postgres walk
        // the index backwards and stop at 25 instead of sorting the table
        // (verified with EXPLAIN: `Index Scan ... Index Cond: (status = 1)`,
        // no Sort node). claim_status is deliberately NOT wedged in between:
        // it is optional, and putting it second would destroy the ordering
        // property for the unfiltered default view. With it filtered the plan
        // degrades gracefully to the same ordered scan plus a cheap filter.
        //
        // NOTE this targets the /super path, where BrandScope does not apply.
        // For brand-scoped callers `brand` is already indexed on its own.
        $this->addIndex('saccos', 'saccos_status_created_at_index', ['status', 'created_at']);

        // ------------------------------------------------------------------
        // notifications
        // ------------------------------------------------------------------
        // Serves the notification list — the single most-called query in the
        // mobile apps. Notifiable::notifications() is morphMany(...)->latest(),
        // confirmed by toSql():
        //   where notifiable_type = ? and notifiable_id = ?
        //   order by created_at desc limit 20
        //
        // The existing (notifiable_type, notifiable_id, read_at) index cannot
        // supply that ordering — read_at sits between the equalities and the
        // sort key — so today every page of a user's notifications sorts that
        // user's whole history. This index is additive, not a replacement:
        // the read_at one still serves unreadNotifications()->count().
        //
        // Worth the write cost on an append-heavy table because created_at is
        // immutable: markAsRead()/markAllRead() write read_at only, so they
        // touch the existing index and never this one.
        $this->addIndex(
            'notifications',
            'notifications_notifiable_created_at_index',
            ['notifiable_type', 'notifiable_id', 'created_at']
        );

        // ------------------------------------------------------------------
        // audit_logs  (append-only, and the table that grows fastest here)
        // ------------------------------------------------------------------
        // Serves the action-equality reads:
        //   TillsOverviewController — where action = 'vehicle.payment_details.changed'
        //                             [+ created_at >= / <=] order by created_at desc, id desc
        //   PlatformDailyDigest     — where action = 'sacco.claimed' and brand = ?
        //                             and created_at >= ?  → count
        //   SaccoDetailController::claimedAt — where action = 'sacco.claimed'
        //                             and subject_id = ? latest('created_at')
        //
        // action (equality) before created_at (range + sort) — the classic
        // ordering, and EXPLAIN confirms it collapses the range and the sort
        // into one index scan with no Sort node. An ASC index is enough for a
        // single-direction ORDER BY DESC; Postgres scans it backwards.
        //
        // brand stays out: it is optional on every one of these queries and
        // already has its own index. subject_id stays out too — see the
        // "not added" note in the report.
        //
        // NOTE: this makes the pre-existing single-column `audit_logs_action_index`
        // a strict prefix, hence redundant. It is NOT dropped here (this
        // migration is additive); the drop lives in
        // 2026_08_07_123000_drop_redundant_indexes, which verifies at runtime
        // that this index exists first.
        $this->addIndex('audit_logs', 'audit_logs_action_created_at_index', ['action', 'created_at']);

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // ------------------------------------------------------------------
        // PostgreSQL-only: prefix-LIKE (pattern_ops) and partial indexes
        // ------------------------------------------------------------------

        // Serves the ALWAYS-PRESENT prefix filter on the three audit consoles:
        //   AccessAuditController::index  — where action LIKE 'access.%'
        //   TenantController::audit       — where action LIKE 'sacco.%'
        //   MoneyLogController::index     — where action LIKE 'vehicle.payment%'
        //                                     or 'payment.%' or 'loyalty.%'
        //   (plus the optional ?action= prefix each of them layers on)
        //
        // Two things make this index necessary rather than nice to have. The
        // database collates en_US.utf8, so a default btree opclass cannot serve
        // `LIKE 'foo%'` at all — without pattern_ops these endpoints sequentially
        // scan the whole audit trail on every page. And Laravel's Postgres
        // grammar renders the comparison as `"action"::text LIKE ?`; verified by
        // EXPLAIN that Postgres looks through that relabel and still matches this
        // index (`Index Cond: ((action)::text ~>=~ 'access.'::text AND ... ~<~ ...)`).
        //
        // varchar_pattern_ops (not text_pattern_ops) because $table->string()
        // makes the column varchar(255). Single-column on purpose: after a range
        // scan on action, a trailing created_at could neither order nor filter.
        $this->addPgIndex('audit_logs', 'audit_logs_action_prefix_index', '(action varchar_pattern_ops)');

        // Serves DirectoryController::suggestedMatches — the duplicate-suspect
        // hint, which runs ONCE PER ROW of the directory page (25 executions per
        // request) against the full SACCO directory:
        //   where brand = ? and id <> ? and LOWER(name) LIKE 'firsttoken%' limit 200
        //
        // brand first: it is an equality and it halves the table. lower(name)
        // second as an expression, with text_pattern_ops so the prefix becomes a
        // range scan (lower() returns text, hence text_ not varchar_pattern_ops).
        // Without this the page evaluates lower() over every SACCO 25 times over.
        // `id <> ?` is an anti-predicate and cannot be indexed.
        $this->addPgIndex('saccos', 'saccos_brand_lower_name_prefix_index', '(brand, lower(name) text_pattern_ops)');

        // Serves the unread badge, which the console header polls, plus its
        // 3-bucket breakdown — five call sites, all of the same shape:
        //   PlatformNotificationController::unreadCount / index (unreadCount key)
        //   PulseController::alerts
        //     where read_at is null                                  → count
        //     where read_at is null and delivery_class = 'alert' and severity = ? → count
        //     where read_at is null and delivery_class = 'review'     → count
        //   PlatformNotificationController::markAllRead
        //     where read_at is null → update
        //
        // PARTIAL on `read_at IS NULL` because that is the one predicate common
        // to all of them and because unread rows are the small minority of a feed
        // an operator works through: the index holds only the live rows, so the
        // count is answered from a few pages. No column in the WHERE clause needs
        // to be in the key, hence (delivery_class, severity) — the two equalities
        // the breakdown adds, most selective usage first. EXPLAIN confirms it is
        // used both bare (Bitmap Index Scan, no Index Cond) and with both
        // equalities. Rows leave the index as they are read, which is also what
        // keeps it small.
        $this->addPgIndex(
            'platform_notifications',
            'platform_notifications_unread_class_severity_index',
            '(delivery_class, severity) WHERE read_at IS NULL'
        );

        // Serves the review queue and the open-alert rollups:
        //   TenantController::reviewQueue — where delivery_class = 'review'
        //       and resolved_at is null order by occurred_at desc limit 25
        //   PlatformDailyDigest           — where resolved_at is null
        //       and delivery_class = 'alert' and brand = ? group by severity
        //   PulseController                — where event = ? and resolved_at is null
        //
        // PARTIAL on `resolved_at IS NULL` for the same reason as above: the
        // queue is by definition the unresolved rows, and it stays small while
        // the feed grows. delivery_class (equality, always present on the queue)
        // then occurred_at DESC so the paginated ORDER BY comes free from the
        // index — verified: `Index Scan ... Index Cond: (delivery_class = 'review')`
        // with no Sort node. Without it, page 2+ of the queue walks the whole
        // occurred_at index discarding resolved rows.
        //
        // DESC is written explicitly here (unlike the Schema-builder indexes
        // above) only because it costs nothing in a raw statement; a backwards
        // scan of an ASC index would do just as well.
        $this->addPgIndex(
            'platform_notifications',
            'platform_notifications_open_class_occurred_at_index',
            '(delivery_class, occurred_at DESC) WHERE resolved_at IS NULL'
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->dropIndexIfExists('platform_notifications', 'platform_notifications_open_class_occurred_at_index');
            $this->dropIndexIfExists('platform_notifications', 'platform_notifications_unread_class_severity_index');
            $this->dropIndexIfExists('saccos', 'saccos_brand_lower_name_prefix_index');
            $this->dropIndexIfExists('audit_logs', 'audit_logs_action_prefix_index');
        }

        $this->dropIndexIfExists('audit_logs', 'audit_logs_action_created_at_index');
        $this->dropIndexIfExists('notifications', 'notifications_notifiable_created_at_index');
        $this->dropIndexIfExists('saccos', 'saccos_status_created_at_index');
        $this->dropIndexIfExists('sacco_users', 'sacco_users_user_id_end_date_index');
        $this->dropIndexIfExists('sacco_users', 'sacco_users_sacco_id_end_date_index');
        $this->dropIndexIfExists('users', 'users_sacco_id_type_index');
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

        $this->addPgIndex($table, $name, '('.implode(', ', array_map($this->quote(...), $columns)).')');
    }

    /**
     * PostgreSQL-only create, for definitions the Schema builder cannot express
     * (operator classes, expressions, explicit DESC, partial WHERE).
     *
     * $spec is the definition verbatim — the parenthesised key list plus any
     * trailing WHERE clause — so the resulting index is byte-for-byte the one
     * the plain-CREATE version produced. Callers must already have established
     * the pgsql driver.
     */
    private function addPgIndex(string $table, string $name, string $spec): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $this->dropIfInvalid($name);

        DB::unprepared(sprintf(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s %s',
            $this->quote($name),
            $this->quote($table),
            $spec,
        ));
    }

    /**
     * Remove $name, undoing exactly what up() created.
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
