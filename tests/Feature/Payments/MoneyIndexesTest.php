<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The money-table indexes added by 2026_08_26_100000, and the property that
 * makes that migration safe to run against a table taking live payments.
 *
 * Two different things are guarded here, and the second matters more than the
 * first.
 *
 * WHAT EXISTS. `mpesa_logs` carried nothing but a primary key from 2023 until
 * that migration, so "did TransID X reach us?" and "has anything arrived since
 * this till was re-registered?" were both sequential scans of the raw-payload
 * table — the two halves of the reconciliation check. Asserting the indexes are
 * present catches a migration that was reverted or never ran; asserting they are
 * VALID catches the subtler failure, an index left behind by a cancelled
 * `CREATE INDEX CONCURRENTLY`, which `pg_indexes` still reports as present while
 * the planner refuses to use it.
 *
 * HOW IT GETS BUILT. On the legacy stack an index build took the system down: a
 * plain `CREATE INDEX` blocks every INSERT for the length of the build, and
 * `mpesas` is taking live Daraja C2B confirmations. So every statement in that
 * migration must be CONCURRENTLY, which in turn requires
 * `$withinTransaction = false` because Laravel wraps migrations in a transaction
 * on PostgreSQL. Deleting that one property does not fail loudly in review — it
 * fails on the production deploy, or worse, someone "fixes" the resulting error
 * by dropping CONCURRENTLY and turns the deploy into an outage. The second test
 * is what stands in the way of that.
 */
final class MoneyIndexesTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = __DIR__.'/../../../database/migrations/2026_08_26_100000_add_money_indexes_for_backfill.php';

    /**
     * Index name => the table it must sit on.
     *
     * @var array<string, string>
     */
    private const EXPECTED = [
        // "Did this payment reach us at all?" — the first question asked about
        // any payment a SACCO reports missing.
        'mpesa_logs_trans_id_index' => 'mpesa_logs',
        // "Is anything arriving since we re-registered this till?" — how the
        // per-till cutover is verified.
        'mpesa_logs_created_at_index' => 'mpesa_logs',
        // Keeps the hourly settlement sweep off a full scan of `mpesas`.
        'mpesas_settlement_deposits_index' => 'mpesas',
    ];

    #[Test]
    public function the_money_indexes_exist_and_the_planner_can_actually_use_them(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('index shape is asserted against PostgreSQL, which is what staging and production run');
        }

        foreach (self::EXPECTED as $index => $table) {
            $row = DB::selectOne(
                'select t.relname as tbl, i.indisvalid, i.indisready
                   from pg_index i
                   join pg_class c on c.oid = i.indexrelid
                   join pg_class t on t.oid = i.indrelid
                   join pg_namespace n on n.oid = c.relnamespace
                  where n.nspname = current_schema()
                    and c.relname = ?',
                [$index],
            );

            $this->assertNotNull($row, "{$index} is missing — {$table} is back to a sequential scan");
            $this->assertSame($table, $row->tbl, "{$index} must be on {$table}");

            // The hazard this catches: a `CREATE INDEX CONCURRENTLY` that failed
            // leaves the index catalogued but `indisvalid = false`. It is never
            // used for reads and still maintained on every write.
            $this->assertTrue((bool) $row->indisvalid, "{$index} exists but is INVALID, so the planner will not use it");
            $this->assertTrue((bool) $row->indisready, "{$index} exists but is not ready");
        }
    }

    #[Test]
    public function the_settlement_index_is_partial_so_the_backfill_does_not_pay_for_it(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('partial indexes are a PostgreSQL feature');
        }

        $definition = (string) DB::scalar(
            "select pg_get_indexdef(c.oid)
               from pg_class c
               join pg_namespace n on n.oid = c.relnamespace
              where n.nspname = current_schema()
                and c.relname = 'mpesas_settlement_deposits_index'"
        );

        // Without the WHERE this becomes an index over every row of `mpesas` —
        // ~300 MB after the backfill and a write on every live confirmation, to
        // answer a question about 0.75% of the table.
        $this->assertStringContainsString('WHERE', $definition, 'the settlement index must stay PARTIAL');
        $this->assertStringContainsString('Organization To Organization Transfer', $definition);
        $this->assertStringContainsString('OD Payment Transfer', $definition);
    }

    /**
     * THE OUTAGE GUARD. Read as text rather than reflection because both halves
     * matter and only one of them is a property: the migration must declare
     * `$withinTransaction = false`, AND every index statement in it must say
     * CONCURRENTLY. Either alone is useless — CONCURRENTLY inside Laravel's
     * transaction throws, and a non-transactional migration full of plain
     * CREATE INDEX is exactly the blocking build that took the legacy system
     * down.
     *
     * Comments are stripped before the scan, because that migration's docblock
     * discusses the very statements it must not contain. Scanning the raw file
     * would fail on its own explanation of why the rule exists.
     */
    #[Test]
    public function the_migration_builds_every_index_without_locking_out_payments(): void
    {
        $source = file_get_contents(self::MIGRATION);

        $this->assertIsString($source, 'the money-index migration is missing');

        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        $this->assertMatchesRegularExpression(
            '/public\s+\$withinTransaction\s*=\s*false\s*;/',
            $code,
            'Laravel wraps migrations in a transaction on PostgreSQL and CREATE INDEX CONCURRENTLY cannot run inside one'
        );

        preg_match_all('/\b(?:CREATE|DROP)\s+INDEX\b(?!\s+CONCURRENTLY)/i', $code, $blocking);

        $this->assertSame(
            [],
            $blocking[0],
            'every index statement on the money tables must be CONCURRENTLY: a plain build blocks every INSERT into `mpesas`, which is taking live payments'
        );
    }
}
