<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\Mpesa;
use App\Models\Summary;
use App\Models\Transaction;
use App\Models\Vehicle;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * app:attribute-coop-settlements — links a bank HO nightly settlement sweep to
 * the bus it belongs to, but ONLY when that bus has no live collection of its
 * own (otherwise the sweep is a duplicate and attributing it double-counts).
 *
 * The real case is KDY 599G: a month of daily Co-op settlements arriving under
 * the SACCO's HO shortcode, tagged only by name, never attributed — while its
 * own till (4321075) was dead. See IdleTillTest for the detector side.
 */
final class CoopSettlementAttributionTest extends QueueTestCase
{
    /**
     * The command now sweeps only RECENT settlements (DEFAULT_WINDOW_DAYS), so
     * these fixtures' dates are no longer incidental -- they have to sit inside
     * the window. Freezing the clock just after them keeps every assertion below
     * exactly as it was, including the literal trans_date '2026-08-19', and makes
     * the suite independent of the real date, which it previously was not: the
     * fixtures are fixed points and the window would have drifted away from them.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-19 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function bus(string $plate, ?string $shortCode = null): Vehicle
    {
        $sacco = $this->makeSacco();
        $vehicle = $this->makeVehicle($sacco, $this->makeUser([], $sacco), $this->makeSeat());
        $vehicle->forceFill(['plate' => $plate, 'merchant_short_code' => $shortCode])->save();

        return $vehicle;
    }

    private function settlement(string $busName, string $amount, ?string $at = null): Mpesa
    {
        return Mpesa::create([
            'TransID' => 'O2O'.$this->nextSequence(),
            'MSISDN' => '254700111222',
            'TransAmount' => $amount,
            'TransTime' => Carbon::parse($at ?? '2026-08-19 03:02:00'),
            'FirstName' => $busName,
            'BusinessShortCode' => '3020809',        // the SACCO HO account
            'TransactionType' => 'Organization To Organization Transfer',
        ]);
    }

    #[Test]
    public function a_settlement_for_a_bus_with_no_live_till_is_attributed(): void
    {
        $vehicle = $this->bus('KDY 599G', '4321075');   // dead till, no live payments
        $this->settlement('NICCO MOVERS-KDY 599G', '20525.87');

        $this->artisan('app:attribute-coop-settlements')->assertExitCode(0);

        $this->assertDatabaseHas('transactions', [
            'vehicle_id' => $vehicle->id,
            'amount' => 20525.87,
            'summarized' => true,
        ]);
        $summary = Summary::where('vehicle_id', $vehicle->id)->where('trans_date', '2026-08-19')->first();
        $this->assertNotNull($summary);
        $this->assertEqualsWithDelta(20525.87, (float) $summary->mpesa_amount, 0.001);
        $this->assertSame(1, (int) $summary->mpesa_txn);
    }

    #[Test]
    public function a_settlement_for_a_bus_that_collects_live_is_skipped(): void
    {
        $vehicle = $this->bus('KDX 486Q', '3427883');   // has a working till

        // A real per-fare payment on its OWN shortcode.
        $live = Mpesa::create([
            'TransID' => 'FARE'.$this->nextSequence(),
            'MSISDN' => '254700111222', 'TransAmount' => '50',
            'TransTime' => Carbon::parse('2026-08-18 12:00:00'),
            'BusinessShortCode' => '3427883', 'TransactionType' => 'Customer Merchant Payment',
        ]);
        Transaction::create(['vehicle_id' => $vehicle->id, 'mpesa_id' => $live->id, 'amount' => 50, 'trans_date' => $live->TransTime]);

        $this->settlement('NICCO MOVERS-KDX 486Q', '21776.62');

        $this->artisan('app:attribute-coop-settlements')->assertExitCode(0);

        // Only the live fare exists; the settlement must NOT have been attributed.
        $this->assertSame(1, Transaction::where('vehicle_id', $vehicle->id)->count());
        $this->assertNull(Transaction::where('vehicle_id', $vehicle->id)->where('amount', 21776.62)->first());
    }

    #[Test]
    public function running_twice_does_not_double_attribute(): void
    {
        $vehicle = $this->bus('KDY 599G', '4321075');
        $this->settlement('NICCO MOVERS-KDY 599G', '16485');

        $this->artisan('app:attribute-coop-settlements')->assertExitCode(0);
        $this->artisan('app:attribute-coop-settlements')->assertExitCode(0);

        $this->assertSame(1, Transaction::where('vehicle_id', $vehicle->id)->count());
        $this->assertEqualsWithDelta(
            16485.0,
            (float) Summary::where('vehicle_id', $vehicle->id)->sum('mpesa_amount'),
            0.001
        );
    }

    #[Test]
    public function a_settlement_whose_name_matches_no_vehicle_is_left_alone(): void
    {
        $this->settlement('SOME OTHER SACCO-KXX 000Z', '5000');

        $this->artisan('app:attribute-coop-settlements')->assertExitCode(0);

        $this->assertSame(0, Transaction::count());
    }

    #[Test]
    public function the_dry_run_writes_nothing(): void
    {
        $vehicle = $this->bus('KDY 599G', '4321075');
        $this->settlement('NICCO MOVERS-KDY 599G', '20000');

        $this->artisan('app:attribute-coop-settlements --dry-run')->assertExitCode(0);

        $this->assertSame(0, Transaction::where('vehicle_id', $vehicle->id)->count());
        $this->assertSame(0, Summary::where('vehicle_id', $vehicle->id)->count());
    }

    #[Test]
    public function a_settlement_older_than_the_window_is_left_alone(): void
    {
        // THE REGRESSION THIS FILE EXISTS TO HOLD. The command was date-unbounded
        // and legacy:import-money put ~1.29M rows with TransTime 2026-07-08..08-08
        // into `mpesas`. An unattended hourly run would have attributed every
        // settlement among them -- transactions written and summaries mutated for
        // months in the past, inside an hour, reviewed by nobody. That is why the
        // schedule line was commented out for a fortnight.
        $vehicle = $this->bus('KDY 599G', '4321075');
        $this->settlement('NICCO MOVERS-KDY 599G', '20525.87', '2026-07-15 03:02:00');

        $this->artisan('app:attribute-coop-settlements')->assertExitCode(0);

        $this->assertSame(0, Transaction::where('vehicle_id', $vehicle->id)->count(),
            'a settlement from the backfill era must be invisible to an unattended run');
        $this->assertSame(0, Summary::where('vehicle_id', $vehicle->id)->count());
    }

    #[Test]
    public function since_reaches_an_old_settlement_when_an_operator_asks_explicitly(): void
    {
        // The escape hatch has to work, or the only way to attribute genuinely
        // unrecorded history would be to widen the schedule -- which is the thing
        // that must never happen.
        $vehicle = $this->bus('KDY 599G', '4321075');
        $this->settlement('NICCO MOVERS-KDY 599G', '20525.87', '2026-07-15 03:02:00');

        $this->artisan('app:attribute-coop-settlements --since=2026-07-01')->assertExitCode(0);

        $this->assertDatabaseHas('transactions', [
            'vehicle_id' => $vehicle->id,
            'amount' => 20525.87,
        ]);
    }

    #[Test]
    public function the_dry_run_still_writes_nothing_when_since_is_widened(): void
    {
        // The reviewed-one-off procedure is `--since=... --dry-run` FIRST. If the
        // two options did not compose, that procedure would write on its first step.
        $vehicle = $this->bus('KDY 599G', '4321075');
        $this->settlement('NICCO MOVERS-KDY 599G', '9999', '2026-07-15 03:02:00');

        $this->artisan('app:attribute-coop-settlements --since=2026-07-01 --dry-run')
            ->assertExitCode(0);

        $this->assertSame(0, Transaction::where('vehicle_id', $vehicle->id)->count());
        $this->assertSame(0, Summary::where('vehicle_id', $vehicle->id)->count());
    }

    #[Test]
    public function the_window_is_reported_so_an_operator_can_see_which_one_they_got(): void
    {
        // The difference between sweeping this week and sweeping the backfill is
        // one flag, and the output is the only place it is visible.
        $this->artisan('app:attribute-coop-settlements')
            ->expectsOutputToContain('window')
            ->expectsOutputToContain('default 7d')
            ->assertExitCode(0);

        $this->artisan('app:attribute-coop-settlements --since=2026-07-01')
            ->expectsOutputToContain('--since given')
            ->assertExitCode(0);
    }
}
