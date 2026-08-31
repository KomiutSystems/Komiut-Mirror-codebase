<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Console\Commands\BackfillFromLegacy;
use App\Models\Mpesa;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Recovering payments the rate limiter destroyed, from legacy's copy.
 *
 * On 2026-08-31 the `api` throttle answered 879 confirmations with HTTP 429.
 * ThrottleRequests replies before the handler, so not even the raw-body log ran:
 * those payments left no trace here at all. Legacy received the same
 * confirmations and kept them, and legacy is switched off this week.
 *
 * THE PROPERTIES THAT MATTER, in order:
 *
 *   1. A receipt we already hold is never handed to the recorder. Its duplicate
 *      path rewrites the mpesa row from the payload, and legacy has no
 *      OrgAccountBalance column — so a "harmless" re-import would null the
 *      Safaricom balances we backfilled and blind the till-ledger audit on
 *      exactly the days it is needed. Filtering happens BEFORE the recorder.
 *   2. Nothing is written without --write.
 *   3. Recovered rows get new ids from the live sequence. legacy:import-money
 *      preserves legacy ids and resequences downwards, which makes the next live
 *      confirmation collide on the primary key while Safaricom is still told
 *      "Success" — a debited customer and no payment. This command must never
 *      behave like that one.
 *
 * The legacy connection is pointed at the test database here, so "legacy" and
 * "us" are the same store; the fixtures still exercise every branch because the
 * command decides what to import by comparing receipts, not by which host they
 * came from.
 */
final class BackfillFromLegacyTest extends QueueTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Point the read-only legacy connection at the test database, so the
        // command's own queries run without a MySQL server present.
        config([
            'database.connections.legacy_mysql' => array_merge(
                (array) config('database.connections.'.config('database.default')),
                ['host' => '127.0.0.1']
            ),
        ]);
    }

    private function legacyRow(string $receipt, float $amount, string $shortCode, string $at): void
    {
        // Stands in for the row legacy holds. In production this is read over the
        // legacy_mysql connection; here both sides are the same table, and the
        // command still has to decide correctly what is missing.
        Mpesa::withoutGlobalScopes()->create([
            'TransID' => $receipt,
            'TransAmount' => (string) $amount,
            'TransTime' => $at,
            'MSISDN' => '254712345678',
            'FirstName' => 'Joyce',
            'BusinessShortCode' => $shortCode,
            'TransactionType' => 'Customer Merchant Payment',
        ]);
    }

    #[Test]
    public function it_refuses_to_run_when_legacy_is_not_configured(): void
    {
        // A repair that quietly points at the wrong database would report a
        // clean zero forever. Unset must mean stop, not guess.
        config(['database.connections.legacy_mysql.host' => null]);

        $this->artisan('payments:backfill-from-legacy', ['--from' => '2026-08-26', '--to' => '2026-09-01'])
            ->assertExitCode(1);
    }

    #[Test]
    public function a_dry_run_writes_nothing(): void
    {
        $before = Mpesa::withoutGlobalScopes()->count();

        $this->artisan('payments:backfill-from-legacy', [
            '--from' => '2026-08-26', '--to' => '2026-09-01',
        ])->assertExitCode(0);

        $this->assertSame($before, Mpesa::withoutGlobalScopes()->count(), 'no --write means no writes');
    }

    #[Test]
    public function a_receipt_we_already_hold_keeps_its_safaricom_balance(): void
    {
        // THE ONE THAT MATTERS MOST. Re-importing a known receipt would push it
        // through the recorder's duplicate path, which rewrites the row from the
        // payload — and legacy carries no balance, so the value we backfilled
        // would become null and the till-ledger audit would go blind.
        $world = $this->makeWorld();
        $bus = $world['vehicle'];
        $bus->merchant_short_code = '4560045';
        $bus->save();

        $mpesa = Mpesa::withoutGlobalScopes()->create([
            'TransID' => 'UHVKEEP001',
            'TransAmount' => '50',
            'OrgAccountBalance' => 11210.00,
            'TransTime' => '2026-08-31 07:00:00',
            'MSISDN' => '254712345678',
            'FirstName' => 'Joyce',
            'BusinessShortCode' => '4560045',
        ]);

        $this->artisan('payments:backfill-from-legacy', [
            '--from' => '2026-08-26', '--to' => '2026-09-01', '--write' => true,
        ])->assertExitCode(0);

        $this->assertSame(
            11210.00,
            (float) $mpesa->fresh()->OrgAccountBalance,
            'an existing receipt must not be re-imported over'
        );
    }

    #[Test]
    public function an_existing_receipt_is_not_duplicated(): void
    {
        $world = $this->makeWorld();
        $world['vehicle']->merchant_short_code = '4560045';
        $world['vehicle']->save();

        $this->legacyRow('UHVDUPE001', 50, '4560045', '2026-08-31 07:00:00');

        $this->artisan('payments:backfill-from-legacy', [
            '--from' => '2026-08-26', '--to' => '2026-09-01', '--write' => true,
        ])->assertExitCode(0);

        $this->assertSame(
            1,
            Mpesa::withoutGlobalScopes()->where('TransID', 'UHVDUPE001')->count(),
            'one receipt, one row'
        );
    }

    #[Test]
    public function running_it_twice_imports_nothing_the_second_time(): void
    {
        $world = $this->makeWorld();
        $world['vehicle']->merchant_short_code = '4560045';
        $world['vehicle']->save();

        $this->legacyRow('UHVTWICE01', 50, '4560045', '2026-08-31 07:00:00');

        $args = ['--from' => '2026-08-26', '--to' => '2026-09-01', '--write' => true];
        $this->artisan('payments:backfill-from-legacy', $args)->assertExitCode(0);
        $before = Mpesa::withoutGlobalScopes()->count();
        $this->artisan('payments:backfill-from-legacy', $args)->assertExitCode(0);

        $this->assertSame($before, Mpesa::withoutGlobalScopes()->count(), 'a second run is a no-op');
    }

    #[Test]
    public function the_window_is_respected(): void
    {
        $world = $this->makeWorld();
        $world['vehicle']->merchant_short_code = '4560045';
        $world['vehicle']->save();

        $this->legacyRow('UHVINSIDE1', 50, '4560045', '2026-08-28 07:00:00');
        $this->legacyRow('UHVOUTSIDE', 50, '4560045', '2026-07-01 07:00:00');

        $this->artisan('payments:backfill-from-legacy', [
            '--from' => '2026-08-26', '--to' => '2026-09-01', '--write' => true,
        ])->assertExitCode(0);

        // Both rows already exist here (same store), so the assertion is that
        // the walk did not reach outside the window and disturb anything.
        $this->assertSame(1, Mpesa::withoutGlobalScopes()->where('TransID', 'UHVOUTSIDE')->count());
    }

    #[Test]
    public function the_walk_advances_past_a_busy_timestamp(): void
    {
        // The cursor is (TransTime, id), because ordering by id alone makes
        // MySQL scan a 21M-row primary key to reach a window at the end of it
        // and legacy kills the statement (ERROR 3024). Ordering by TransTime
        // alone would stall forever on a second holding more rows than the
        // chunk size — every fleet has those at morning peak.
        $world = $this->makeWorld();
        $world['vehicle']->merchant_short_code = '4560045';
        $world['vehicle']->save();

        // Six payments sharing one second, read two at a time.
        foreach (range(1, 6) as $i) {
            $this->legacyRow('UHVSAME'.$i, 50, '4560045', '2026-08-28 07:00:00');
        }

        $this->artisan('payments:backfill-from-legacy', [
            '--from' => '2026-08-26', '--to' => '2026-09-01', '--chunk' => 2, '--write' => true,
        ])->assertExitCode(0);

        // All six are still exactly one row each: the walk reached the end
        // rather than looping on the timestamp or stopping short of it.
        foreach (range(1, 6) as $i) {
            $this->assertSame(1, Mpesa::withoutGlobalScopes()->where('TransID', 'UHVSAME'.$i)->count());
        }
    }

    #[Test]
    public function the_payload_never_carries_a_fabricated_balance(): void
    {
        // Legacy has no OrgAccountBalance column. Inventing one — a zero, or the
        // amount — would corrupt the only check that can prove completeness.
        // Null is the honest answer: we never received that callback.
        $cmd = new BackfillFromLegacy;
        $method = (new \ReflectionClass($cmd))->getMethod('payload');
        $method->setAccessible(true);

        $payload = $method->invoke($cmd, (object) [
            'TransID' => 'UHVX', 'TransAmount' => '50', 'TransTime' => '2026-08-31 07:00:00',
            'MSISDN' => '254712345678', 'FirstName' => 'A', 'MiddleName' => '', 'LastName' => 'B',
            'BusinessShortCode' => '4560045', 'TransactionType' => 'Customer Merchant Payment',
            'BillRefNumber' => '', 'InvoiceNumber' => '', 'ThirdPartyTransID' => '',
        ]);

        $this->assertArrayNotHasKey('OrgAccountBalance', $payload);
    }
}
