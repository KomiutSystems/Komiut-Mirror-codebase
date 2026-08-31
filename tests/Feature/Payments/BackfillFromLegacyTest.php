<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Console\Commands\BackfillFromLegacy;
use App\Models\Mpesa;
use App\Models\Transaction;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
 * LEGACY IS A SEPARATE STORE HERE, on its own sqlite connection. The first
 * version of this file pointed `legacy_mysql` at the test database, so every
 * fixture row existed on BOTH sides and the command correctly skipped all of
 * them — the suite went green without ever importing anything. A money command
 * whose tests never exercise the write is worse than no tests, because it reads
 * as covered.
 *
 * THE PROPERTIES THAT MATTER, in order:
 *
 *   1. A receipt we already hold is never handed to the recorder. Its duplicate
 *      path rewrites the mpesa row from the payload, and legacy has no
 *      OrgAccountBalance column — so a re-import would null the Safaricom
 *      balances we backfilled and blind the till-ledger audit on exactly the
 *      days it is needed.
 *   2. Only money that lands on a bus we can name is imported. Of the 15,273
 *      payments legacy held that we did not, 23 (KES 384,175) were the SACCO's
 *      nightly bank sweeps and 3,935 (KES 347,469) sat on a paybill shared by
 *      34 vehicles. Neither is a fare on a matatu.
 *   3. Nothing is written without --write, and a second run imports nothing.
 */
final class BackfillFromLegacyTest extends QueueTestCase
{
    private const LEGACY = 'legacy_mysql';

    protected function setUp(): void
    {
        parent::setUp();

        // A real second database, so "legacy has it and we do not" is expressible.
        config([
            'database.connections.'.self::LEGACY => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                // isAvailable() checks host and username, so both must be set.
                'host' => '127.0.0.1',
                'username' => 'legacy',
                'password' => '',
            ],
        ]);

        Schema::connection(self::LEGACY)->create('mpesas', function ($t): void {
            $t->increments('id');
            $t->string('TransID')->nullable();
            $t->string('TransAmount')->nullable();
            $t->dateTime('TransTime')->nullable();
            $t->string('MSISDN')->nullable();
            $t->string('FirstName')->nullable();
            $t->string('MiddleName')->nullable();
            $t->string('LastName')->nullable();
            $t->string('BusinessShortCode')->nullable();
            $t->string('TransactionType')->nullable();
            $t->string('BillRefNumber')->nullable();
            $t->string('InvoiceNumber')->nullable();
            $t->string('ThirdPartyTransID')->nullable();
        });
    }

    /** A payment that exists ONLY in legacy. */
    private function inLegacy(string $receipt, float $amount, string $shortCode, string $at = '2026-08-28 07:00:00'): void
    {
        DB::connection(self::LEGACY)->table('mpesas')->insert([
            'TransID' => $receipt,
            'TransAmount' => (string) $amount,
            'TransTime' => $at,
            'MSISDN' => '254712345678',
            'FirstName' => 'Joyce',
            'BusinessShortCode' => $shortCode,
            'TransactionType' => 'Customer Merchant Payment',
        ]);
    }

    private function busOn(array $world, string $shortCode): Vehicle
    {
        $bus = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);
        $bus->merchant_short_code = $shortCode;
        $bus->save();

        return $bus->fresh();
    }

    private function backfill(array $extra = []): void
    {
        $this->artisan('payments:backfill-from-legacy', array_merge(
            ['--from' => '2026-08-26', '--to' => '2026-09-01'],
            $extra
        ))->assertExitCode(0);
    }

    #[Test]
    public function a_payment_only_legacy_has_is_recovered_onto_its_bus(): void
    {
        $world = $this->makeWorld();
        $bus = $this->busOn($world, '4560045');
        $this->inLegacy('UHVLOST001', 150, '4560045');

        $this->backfill(['--write' => true]);

        $mpesa = Mpesa::withoutGlobalScopes()->where('TransID', 'UHVLOST001')->first();
        $this->assertNotNull($mpesa, 'the payment must now exist here');
        $this->assertSame(150.0, (float) $mpesa->TransAmount);

        $txn = Transaction::withoutGlobalScopes()->where('mpesa_id', $mpesa->id)->first();
        $this->assertNotNull($txn, 'and it must reach a bus, or it is not takings');
        $this->assertSame($bus->id, (int) $txn->vehicle_id);
    }

    #[Test]
    public function a_recovered_payment_gets_a_live_id_not_the_legacy_one(): void
    {
        // legacy:import-money preserves legacy ids and resequences downwards,
        // which makes the next live confirmation collide on the primary key
        // while Safaricom is still told "Success" — a debited customer and no
        // payment. This command must never behave like that one.
        $world = $this->makeWorld();
        $this->busOn($world, '4560045');
        $this->inLegacy('UHVLOST001', 150, '4560045');

        $legacyId = (int) DB::connection(self::LEGACY)->table('mpesas')->value('id');

        $this->backfill(['--write' => true]);

        $mpesa = Mpesa::withoutGlobalScopes()->where('TransID', 'UHVLOST001')->first();
        $this->assertNotSame($legacyId, (int) $mpesa->id, 'ids come from the live sequence');
    }

    #[Test]
    public function a_dry_run_writes_nothing(): void
    {
        $world = $this->makeWorld();
        $this->busOn($world, '4560045');
        $this->inLegacy('UHVLOST001', 150, '4560045');

        $this->backfill();

        $this->assertSame(0, Mpesa::withoutGlobalScopes()->where('TransID', 'UHVLOST001')->count());
    }

    #[Test]
    public function a_receipt_we_already_hold_keeps_its_safaricom_balance(): void
    {
        // THE ONE THAT MATTERS MOST. Re-importing a known receipt would push it
        // through the recorder's duplicate path, which rewrites the row from the
        // payload — and legacy carries no balance, so the value we backfilled
        // would become null and the till-ledger audit would go blind.
        $world = $this->makeWorld();
        $this->busOn($world, '4560045');

        $mpesa = Mpesa::withoutGlobalScopes()->create([
            'TransID' => 'UHVKEEP001',
            'TransAmount' => '50',
            'OrgAccountBalance' => 11210.00,
            'TransTime' => '2026-08-28 07:00:00',
            'MSISDN' => '254712345678',
            'FirstName' => 'Joyce',
            'BusinessShortCode' => '4560045',
        ]);

        $this->inLegacy('UHVKEEP001', 50, '4560045');

        $this->backfill(['--write' => true]);

        $this->assertSame(11210.00, (float) $mpesa->fresh()->OrgAccountBalance);
        $this->assertSame(1, Mpesa::withoutGlobalScopes()->where('TransID', 'UHVKEEP001')->count());
    }

    #[Test]
    public function running_it_twice_imports_nothing_the_second_time(): void
    {
        $world = $this->makeWorld();
        $this->busOn($world, '4560045');
        $this->inLegacy('UHVTWICE01', 50, '4560045');

        $this->backfill(['--write' => true]);
        $after = Mpesa::withoutGlobalScopes()->count();
        $this->backfill(['--write' => true]);

        $this->assertSame($after, Mpesa::withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_bank_sweep_is_not_imported_as_a_fare(): void
    {
        // 23 of the 15,273 absent payments, worth KES 384,175, sit on collection
        // accounts belonging to no bus — the SACCO's nightly sweeps to its own
        // bank, averaging KES 16,700 each. Not takings.
        $world = $this->makeWorld();
        $this->busOn($world, '4560045');
        $this->inLegacy('UHVSWEEP99', 24710, '5339736', '2026-08-28 03:02:00');

        $this->backfill(['--write' => true]);

        $this->assertSame(0, Mpesa::withoutGlobalScopes()->where('TransID', 'UHVSWEEP99')->count());
    }

    #[Test]
    public function a_shortcode_shared_by_many_buses_is_left_alone(): void
    {
        // 880100 is the NCBA aggregator paybill, shared by 34 vehicles in
        // production and carrying 3,935 of the absent payments. It identifies
        // the bank, not a bus, so there is no one matatu to credit.
        $world = $this->makeWorld();
        $this->busOn($world, '880100');
        $this->busOn($world, '880100');
        $this->inLegacy('UHVAGG0001', 50, '880100');

        $this->backfill(['--write' => true]);

        $this->assertSame(0, Mpesa::withoutGlobalScopes()->where('TransID', 'UHVAGG0001')->count());
    }

    #[Test]
    public function the_unattributable_can_be_imported_deliberately(): void
    {
        // The default refuses them; the flag is the way to say "yes, I know".
        $world = $this->makeWorld();
        $this->busOn($world, '4560045');
        $this->inLegacy('UHVSWEEP99', 24710, '5339736', '2026-08-28 03:02:00');

        $this->backfill(['--write' => true, '--include-unattributable' => true]);

        $this->assertSame(1, Mpesa::withoutGlobalScopes()->where('TransID', 'UHVSWEEP99')->count());
    }

    #[Test]
    public function the_window_is_respected(): void
    {
        $world = $this->makeWorld();
        $this->busOn($world, '4560045');

        $this->inLegacy('UHVINSIDE1', 50, '4560045', '2026-08-28 07:00:00');
        $this->inLegacy('UHVOUTSIDE', 50, '4560045', '2026-07-01 07:00:00');

        $this->backfill(['--write' => true]);

        $this->assertSame(1, Mpesa::withoutGlobalScopes()->where('TransID', 'UHVINSIDE1')->count());
        $this->assertSame(0, Mpesa::withoutGlobalScopes()->where('TransID', 'UHVOUTSIDE')->count());
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
        $this->busOn($world, '4560045');

        foreach (range(1, 6) as $i) {
            $this->inLegacy('UHVSAME'.$i, 50, '4560045', '2026-08-28 07:00:00');
        }

        $this->backfill(['--chunk' => 2, '--write' => true]);

        foreach (range(1, 6) as $i) {
            $this->assertSame(
                1,
                Mpesa::withoutGlobalScopes()->where('TransID', 'UHVSAME'.$i)->count(),
                'every payment in a busy second is recovered exactly once'
            );
        }
    }

    #[Test]
    public function it_refuses_to_run_when_legacy_is_not_configured(): void
    {
        // A repair that quietly points at the wrong database would report a
        // clean zero forever. Unset must mean stop, not guess.
        config(['database.connections.'.self::LEGACY.'.host' => null]);

        $this->artisan('payments:backfill-from-legacy', [
            '--from' => '2026-08-26', '--to' => '2026-09-01',
        ])->assertExitCode(1);
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
