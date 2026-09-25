<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\Mpesa;
use App\Models\MpesaLog;
use App\Models\Summary;
use App\Models\Transaction;
use App\Models\Vehicle;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Recovering undelivered payments from an M-Pesa portal statement.
 *
 * The fixture is the layout of the real export NICCO's operator produced for
 * KDV 672W on 2026-09-25 (header block, then a "Receipt No." table), written
 * as CSV so the test needs no binary file. PhpSpreadsheet reads the .xls the
 * portal emits through the same path.
 */
final class ImportMpesaStatementTest extends QueueTestCase
{
    private const TILL = '3702865';

    private function bus(): Vehicle
    {
        $vehicle = $this->makeWorld()['vehicle'];
        $vehicle->forceFill(['merchant_short_code' => self::TILL])->save();

        return $vehicle->fresh();
    }

    private function held(Vehicle $bus, string $receipt, string $when, float $amount): void
    {
        $m = new Mpesa;
        $m->forceFill(['TransID' => $receipt, 'TransAmount' => (string) $amount, 'TransTime' => $when, 'MSISDN' => 'hash',
            'FirstName' => 'HELD', 'BusinessShortCode' => self::TILL, 'OrgAccountBalance' => '8480.00', 'TransactionType' => 'Buy Goods'])->save();
        Transaction::withoutGlobalScopes()->create(['mpesa_id' => $m->id, 'vehicle_id' => $bus->id, 'amount' => $amount, 'trans_date' => $when]);
    }

    /** @param list<array{0:string,1:string,2:string,3:string,4:string,5?:string}> $movements receipt, time, paid in, withdrawn, status, reason */
    private function statement(array $movements, string $shortCode = self::TILL): string
    {
        $lines = [
            ['Account Holder:', 'NICCO MOVERS - KDV 672W'],
            ['Short Code:', $shortCode],
            ['Account:', 'Merchant Account'],
            ['Time Period:', 'From', '25-09-2026 00:00:00', 'To', '25-09-2026 23:59:59'],
            ['Operator:', 'Henry', 'Organization:', 'NICCO MOVERS - KDV 672W'],
            ['Opening Balance:', '0.0', 'Closing Balance:', '8530.0'],
            ['Receipt No.', 'Completion Time', 'Initiation Time', 'Details', 'Transaction Status', 'Paid In', 'Withdrawn', 'Balance',
                'Balance Confirmed', 'Reason Type', 'Other Party Info', 'Linked Transaction ID', 'A/C No.', 'Currency'],
        ];
        foreach ($movements as $m) {
            $lines[] = [$m[0], $m[1], $m[1], 'Merchant Payment', $m[4], $m[2], $m[3], '0', 'true', $m[5] ?? 'Pay Merchant',
                '070****366 - SAMUEL **** KIMANI', '', '', 'KES'];
        }
        $path = tempnam(sys_get_temp_dir(), 'stmt').'.csv';
        $fh = fopen($path, 'w');
        foreach ($lines as $l) {
            fputcsv($fh, $l);
        }
        fclose($fh);

        return $path;
    }

    #[Test]
    public function the_completed_payments_we_never_received_are_recorded_on_the_bus(): void
    {
        $bus = $this->bus();
        $this->held($bus, 'UIPDS807K6', '2026-09-25 10:32:37', 30);
        $file = $this->statement([
            ['UIPDS807K6', '25-09-2026 10:32:37', '30.0', '', 'Completed'],                     // delivered
            ['UIP7H7OI90', '25-09-2026 06:04:11', '100.0', '', 'Completed'],                    // never delivered
            ['UIPOE815WT', '25-09-2026 06:05:40', '200.0', '', 'Completed'],                    // never delivered
            ['UIPSZ4CQV5', '25-09-2026 03:31:04', '', '-22779.95', 'Completed', 'Merchant Account to Organization Settlement Account'],
            ['UIPFAILED1', '25-09-2026 07:00:00', '50.0', '', 'Failed'],
        ]);

        $this->artisan('payments:import-statement', ['files' => [$file], '--write' => true])
            ->expectsOutputToContain('missing 2 (KES 300.00), recorded 2')->assertSuccessful();

        foreach (['UIP7H7OI90' => ['2026-09-25 06:04:11', 100.0], 'UIPOE815WT' => ['2026-09-25 06:05:40', 200.0]] as $receipt => [$when, $amount]) {
            $m = Mpesa::withoutGlobalScopes()->where('TransID', $receipt)->sole();
            $this->assertSame($when, substr((string) $m->TransTime, 0, 19), 'the portal prints Nairobi time; stored as that wall clock');
            $this->assertSame($amount, (float) $m->TransAmount);
            $this->assertSame('SAMUEL', $m->FirstName);
            $this->assertSame('KIMANI', $m->LastName, 'masked name parts are dropped, not stored as stars');
            $this->assertSame('070****366', $m->MSISDN);
            $this->assertSame($bus->id, (int) Transaction::withoutGlobalScopes()->where('mpesa_id', $m->id)->value('vehicle_id'));
            $this->assertSame('mpesa-statement', MpesaLog::where('trans_id', $receipt)->value('ip_address'));
        }

        $this->assertSame(0, Mpesa::withoutGlobalScopes()->whereIn('TransID', ['UIPSZ4CQV5', 'UIPFAILED1'])->count(), 'sweeps and failed movements are not fares');
        $this->assertSame('HELD', Mpesa::withoutGlobalScopes()->where('TransID', 'UIPDS807K6')->sole()->FirstName, 'a delivered receipt is untouched');
        $this->assertEqualsWithDelta(300.0, (float) Summary::withoutGlobalScopes()->where('vehicle_id', $bus->id)->sum('mpesa_amount'), 0.01);
    }

    #[Test]
    public function importing_the_same_statement_twice_records_nothing_the_second_time(): void
    {
        $bus = $this->bus();
        $this->held($bus, 'UIPDS807K6', '2026-09-25 10:32:37', 30);
        $file = $this->statement([['UIPDS807K6', '25-09-2026 10:32:37', '30.0', '', 'Completed'], ['UIP7H7OI90', '25-09-2026 06:04:11', '100.0', '', 'Completed']]);

        $this->artisan('payments:import-statement', ['files' => [$file], '--write' => true])->assertSuccessful();
        $this->artisan('payments:import-statement', ['files' => [$file], '--write' => true])
            ->expectsOutputToContain('missing 0 (KES 0.00), recorded 0')->assertSuccessful();

        $this->assertSame(1, Mpesa::withoutGlobalScopes()->where('TransID', 'UIP7H7OI90')->count());
    }

    #[Test]
    public function a_dry_run_writes_nothing(): void
    {
        $bus = $this->bus();
        $this->held($bus, 'UIPDS807K6', '2026-09-25 10:32:37', 30);
        $file = $this->statement([['UIPDS807K6', '25-09-2026 10:32:37', '30.0', '', 'Completed'], ['UIP7H7OI90', '25-09-2026 06:04:11', '100.0', '', 'Completed']]);

        $this->artisan('payments:import-statement', ['files' => [$file]])->expectsOutputToContain('DRY RUN')->assertSuccessful();

        $this->assertSame(0, Mpesa::withoutGlobalScopes()->where('TransID', 'UIP7H7OI90')->count());
    }

    #[Test]
    public function a_statement_whose_clock_disagrees_with_ours_is_refused(): void
    {
        $bus = $this->bus();
        $this->held($bus, 'UIPDS807K6', '2026-09-25 10:32:37', 30);
        $file = $this->statement([['UIPDS807K6', '25-09-2026 07:32:37', '30.0', '', 'Completed'], ['UIP7H7OI90', '25-09-2026 03:04:11', '100.0', '', 'Completed']]);

        $this->artisan('payments:import-statement', ['files' => [$file], '--write' => true])->assertFailed();
        $this->assertSame(0, Mpesa::withoutGlobalScopes()->where('TransID', 'UIP7H7OI90')->count());
    }

    #[Test]
    public function a_till_that_matches_no_bus_is_refused_rather_than_recorded_unattributed(): void
    {
        $this->bus();
        $file = $this->statement([['UIP7H7OI90', '25-09-2026 06:04:11', '100.0', '', 'Completed']], '9999999');

        $this->artisan('payments:import-statement', ['files' => [$file], '--write' => true])
            ->expectsOutputToContain('matches no single bus')->assertFailed();
        $this->assertSame(0, Mpesa::withoutGlobalScopes()->where('TransID', 'UIP7H7OI90')->count());
    }

    #[Test]
    public function a_file_that_is_not_a_statement_is_refused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'stmt').'.csv';
        file_put_contents($path, "hello,world\n1,2\n");

        $this->artisan('payments:import-statement', ['files' => [$path], '--write' => true])
            ->expectsOutputToContain('Not an M-Pesa portal statement')->assertFailed();
    }
}
