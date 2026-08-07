<?php

declare(strict_types=1);

namespace Tests\Feature\Super;

use App\Models\Sacco;
use App\Models\Summary;
use App\Models\SummarySync;
use App\Models\Transaction;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression cover for app:generate-vehicle-summaries.
 *
 * Two defects motivated these tests:
 *   1. the M-Pesa transaction COUNT was written into the `cash_txn` column, so
 *      cash and M-Pesa metrics were indistinguishable in the summary table;
 *   2. a single $summary model was reassigned for every vehicle in the run, so
 *      one vehicle's money landed on another vehicle's row and no new rows were
 *      ever created.
 *
 * Every fixture below therefore gives the four metrics MUTUALLY DISTINCT values:
 * with equal counts or equal amounts a swapped assignment still passes.
 *
 * Rows are built explicitly rather than with factories (no Vehicle factory
 * exists) — matching the approach in tests/Feature/Queues/QueueTestCase.
 */
class GenerateVehicleSummariesTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    private function makeVehicle(): Vehicle
    {
        $n = ++$this->sequence;

        $sacco = Sacco::create([
            'name' => "Sacco {$n}",
            'phone' => '07000000' . str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'status' => 1,
            'brand' => 'testing',
        ]);

        return Vehicle::create([
            'plate' => 'KDA' . str_pad((string) $n, 3, '0', STR_PAD_LEFT) . 'X',
            'sacco_id' => $sacco->id,
            'status' => true,
            'brand' => 'testing',
        ]);
    }

    /**
     * An M-Pesa transaction is one with `mpesa_id` set; a cash transaction one
     * with `cash_id` set. The command keys every metric off those two columns.
     * The ids only have to be > 0 — no Mpesa/Cash row is needed for the aggregate.
     */
    private function makeMpesaTxn(Vehicle $vehicle, float $amount, string $date): Transaction
    {
        return Transaction::create([
            'vehicle_id' => $vehicle->id,
            'mpesa_id' => ++$this->sequence,
            'amount' => $amount,
            'trans_date' => $date . ' 09:00:00',
        ]);
    }

    private function makeCashTxn(Vehicle $vehicle, float $amount, string $date): Transaction
    {
        return Transaction::create([
            'vehicle_id' => $vehicle->id,
            'cash_id' => ++$this->sequence,
            'amount' => $amount,
            'trans_date' => $date . ' 10:00:00',
        ]);
    }

    /**
     * Pin the run to a known day. With no SummarySync row the command derives
     * the date from the latest transaction, so the cursor is written explicitly
     * to keep the fixtures and the run in agreement.
     */
    private function syncTo(string $date): void
    {
        SummarySync::create(['sync_date' => $date, 'status' => false]);
    }

    #[Test]
    public function each_metric_lands_in_its_own_column(): void
    {
        $date = '2026-08-01';
        $vehicle = $this->makeVehicle();

        // 2 M-Pesa txns worth 300 total; 3 cash txns worth 700 total.
        // All four expected values differ (300 / 700 / 2 / 3), so any swap fails.
        $this->makeMpesaTxn($vehicle, 100, $date);
        $this->makeMpesaTxn($vehicle, 200, $date);
        $this->makeCashTxn($vehicle, 100, $date);
        $this->makeCashTxn($vehicle, 200, $date);
        $this->makeCashTxn($vehicle, 400, $date);

        $this->syncTo($date);

        $this->artisan('app:generate-vehicle-summaries')->assertExitCode(0);

        $summary = Summary::where('vehicle_id', $vehicle->id)->where('trans_date', $date)->firstOrFail();

        $this->assertEquals(300.0, (float) $summary->mpesa_amount, 'mpesa_amount must be the M-Pesa amount SUM');
        $this->assertEquals(700.0, (float) $summary->cash_amount, 'cash_amount must be the cash amount SUM');
        $this->assertSame(2, (int) $summary->mpesa_txn, 'mpesa_txn must be the M-Pesa row COUNT');
        // The original defect: cash_txn was assigned the M-Pesa count (2).
        $this->assertSame(3, (int) $summary->cash_txn, 'cash_txn must be the cash row COUNT, not the M-Pesa count');
    }

    #[Test]
    public function two_vehicles_in_one_run_get_their_own_rows_with_their_own_numbers(): void
    {
        $date = '2026-08-01';
        $vehicleA = $this->makeVehicle();
        $vehicleB = $this->makeVehicle();

        // A: 1 mpesa / 500, 2 cash / 150
        $this->makeMpesaTxn($vehicleA, 500, $date);
        $this->makeCashTxn($vehicleA, 50, $date);
        $this->makeCashTxn($vehicleA, 100, $date);

        // B: 3 mpesa / 90, 1 cash / 1000 — no value shared with A, so a
        // cross-write between the two rows cannot go unnoticed.
        $this->makeMpesaTxn($vehicleB, 30, $date);
        $this->makeMpesaTxn($vehicleB, 30, $date);
        $this->makeMpesaTxn($vehicleB, 30, $date);
        $this->makeCashTxn($vehicleB, 1000, $date);

        $this->syncTo($date);

        $this->artisan('app:generate-vehicle-summaries')->assertExitCode(0);

        // One row per vehicle: the old object-reuse bug produced a single row.
        $this->assertSame(2, Summary::where('trans_date', $date)->count());

        $a = Summary::where('vehicle_id', $vehicleA->id)->where('trans_date', $date)->firstOrFail();
        $this->assertEquals(500.0, (float) $a->mpesa_amount);
        $this->assertEquals(150.0, (float) $a->cash_amount);
        $this->assertSame(1, (int) $a->mpesa_txn);
        $this->assertSame(2, (int) $a->cash_txn);

        $b = Summary::where('vehicle_id', $vehicleB->id)->where('trans_date', $date)->firstOrFail();
        $this->assertEquals(90.0, (float) $b->mpesa_amount);
        $this->assertEquals(1000.0, (float) $b->cash_amount);
        $this->assertSame(3, (int) $b->mpesa_txn);
        $this->assertSame(1, (int) $b->cash_txn);
    }

    #[Test]
    public function it_creates_a_summary_row_when_none_exists_yet(): void
    {
        $date = '2026-08-01';
        $vehicle = $this->makeVehicle();
        $this->makeMpesaTxn($vehicle, 250, $date);

        $this->syncTo($date);

        $this->assertSame(0, Summary::count(), 'precondition: the day has no summary row');

        $this->artisan('app:generate-vehicle-summaries')->assertExitCode(0);

        // The old revision only ever mutated pre-existing rows, so a day that
        // started with no summary stayed empty forever.
        $this->assertSame(1, Summary::count());
        $this->assertEquals(250.0, (float) Summary::firstOrFail()->mpesa_amount);
    }

    #[Test]
    public function a_rerun_is_idempotent_and_does_not_double_count(): void
    {
        $date = '2026-08-01';
        $vehicle = $this->makeVehicle();
        $this->makeMpesaTxn($vehicle, 100, $date);
        $this->makeCashTxn($vehicle, 400, $date);

        $this->syncTo($date);

        $this->artisan('app:generate-vehicle-summaries')->assertExitCode(0);
        $this->artisan('app:generate-vehicle-summaries')->assertExitCode(0);

        $this->assertSame(1, Summary::count(), 'a rerun must reuse the row, not add another');

        $summary = Summary::firstOrFail();
        $this->assertEquals(100.0, (float) $summary->mpesa_amount);
        $this->assertEquals(400.0, (float) $summary->cash_amount);
        $this->assertSame(1, (int) $summary->mpesa_txn);
        $this->assertSame(1, (int) $summary->cash_txn);
    }

    #[Test]
    public function it_does_not_pull_the_next_days_transactions_into_todays_row(): void
    {
        $date = '2026-08-01';
        $vehicle = $this->makeVehicle();
        $this->makeMpesaTxn($vehicle, 100, $date);

        // trans_date is a timestamp, and the old inclusive whereBetween window
        // ran to the NEXT day's 00:00:00 — so a midnight transaction was counted
        // into both days.
        Transaction::create([
            'vehicle_id' => $vehicle->id,
            'mpesa_id' => ++$this->sequence,
            'amount' => 999,
            'trans_date' => '2026-08-02 00:00:00',
        ]);

        $this->syncTo($date);

        $this->artisan('app:generate-vehicle-summaries')->assertExitCode(0);

        $summary = Summary::where('trans_date', $date)->firstOrFail();
        $this->assertEquals(100.0, (float) $summary->mpesa_amount, 'next-day midnight money must not be in this row');
        $this->assertSame(1, (int) $summary->mpesa_txn);
    }

    #[Test]
    public function dry_run_reports_the_difference_and_writes_nothing(): void
    {
        $date = '2026-08-01';
        $vehicle = $this->makeVehicle();
        $this->makeMpesaTxn($vehicle, 100, $date);
        $this->makeCashTxn($vehicle, 400, $date);

        // A stored row carrying the wrong numbers, as the buggy revision would
        // have left it.
        Summary::create([
            'vehicle_id' => $vehicle->id,
            'trans_date' => $date,
            'mpesa_amount' => 0,
            'cash_amount' => 0,
            'mpesa_txn' => 0,
            'cash_txn' => 0,
        ]);

        $this->syncTo($date);

        $this->artisan('app:generate-vehicle-summaries', ['--dry-run' => true])
            ->expectsOutputToContain('1 row(s) would change')
            ->expectsOutputToContain('Nothing was written')
            ->assertExitCode(0);

        // Historical money must be untouched by a reporting run.
        $summary = Summary::firstOrFail();
        $this->assertEquals(0.0, (float) $summary->mpesa_amount);
        $this->assertEquals(0.0, (float) $summary->cash_amount);
        $this->assertSame(0, (int) $summary->mpesa_txn);
        $this->assertSame(0, (int) $summary->cash_txn);
    }

    #[Test]
    public function dry_run_does_not_create_a_summary_or_move_the_sync_cursor(): void
    {
        $date = '2026-08-01';
        $vehicle = $this->makeVehicle();
        $this->makeMpesaTxn($vehicle, 100, $date);

        // No SummarySync row on purpose: the real run would create the cursor,
        // and inspecting the data must not do that for it.
        $this->artisan('app:generate-vehicle-summaries', ['--dry-run' => true])
            ->expectsOutputToContain('would create')
            ->assertExitCode(0);

        $this->assertSame(0, Summary::count(), 'a dry run must not create summary rows');
        $this->assertSame(0, SummarySync::count(), 'a dry run must not move the cursor');
    }

    /**
     * --date is the human-driven remediation path: it must summarise exactly the
     * day it is handed, and must not disturb the cursor the scheduler owns.
     */
    #[Test]
    public function the_date_option_targets_that_day_and_leaves_the_sync_cursor_untouched(): void
    {
        $vehicle = $this->makeVehicle();
        $this->makeCashTxn($vehicle, 111, '2026-08-01');
        $this->makeCashTxn($vehicle, 222, '2026-08-05');

        // A cursor pointing somewhere else entirely; --date must not touch it.
        $this->syncTo('2026-08-01');

        $this->artisan('app:generate-vehicle-summaries', ['--date' => '2026-08-05'])
            ->assertExitCode(0);

        // Only the requested day was summarised.
        $this->assertSame(1, Summary::count());
        $summary = Summary::firstOrFail();
        $this->assertSame('2026-08-05', Carbon::parse($summary->trans_date)->toDateString());
        $this->assertEquals(222.0, (float) $summary->cash_amount);
        $this->assertSame(1, (int) $summary->cash_txn);

        $sync = SummarySync::firstOrFail();
        $this->assertSame('2026-08-01', Carbon::parse($sync->sync_date)->toDateString(), 'sync_date must not move');
        $this->assertFalse((bool) $sync->status, 'status must not be flipped by a --date run');
        $this->assertSame(1, SummarySync::count(), '--date must not create a cursor row');
    }

    #[Test]
    public function the_date_option_can_be_combined_with_dry_run_to_inspect_a_past_day(): void
    {
        $vehicle = $this->makeVehicle();
        $this->makeMpesaTxn($vehicle, 640, '2026-07-15');

        $this->artisan('app:generate-vehicle-summaries', ['--date' => '2026-07-15', '--dry-run' => true])
            ->expectsOutputToContain('would create')
            ->expectsOutputToContain('Nothing was written.')
            ->assertExitCode(0);

        $this->assertSame(0, Summary::count());
        $this->assertSame(0, SummarySync::count());
    }

    #[Test]
    public function it_ignores_transactions_with_no_vehicle(): void
    {
        $date = '2026-08-01';

        // The C2B path leaves vehicle_id null when no vehicle resolves; such a
        // row must not become a vehicle-less summary.
        Transaction::create([
            'mpesa_id' => ++$this->sequence,
            'amount' => 750,
            'trans_date' => $date . ' 11:00:00',
        ]);

        $this->syncTo($date);

        $this->artisan('app:generate-vehicle-summaries')->assertExitCode(0);

        $this->assertSame(0, Summary::count());
    }

    #[Test]
    public function it_leaves_expense_fee_amount_alone(): void
    {
        $date = '2026-08-01';
        $vehicle = $this->makeVehicle();
        $this->makeCashTxn($vehicle, 300, $date);

        Summary::create([
            'vehicle_id' => $vehicle->id,
            'trans_date' => $date,
            'mpesa_amount' => 0,
            'cash_amount' => 0,
            'mpesa_txn' => 0,
            'cash_txn' => 0,
            'expense_fee_amount' => '42',
        ]);

        $this->syncTo($date);

        $this->artisan('app:generate-vehicle-summaries')->assertExitCode(0);

        $summary = Summary::firstOrFail();
        $this->assertEquals(300.0, (float) $summary->cash_amount, 'the recompute must still land');
        $this->assertSame('42', $summary->expense_fee_amount, 'a column the command does not derive must survive');
    }
}
