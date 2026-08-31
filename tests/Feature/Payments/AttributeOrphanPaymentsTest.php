<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\Mpesa;
use App\Models\Summary;
use App\Models\Transaction;
use App\Models\Vehicle;
use App\Services\Mpesa\VehicleByShortCode;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Putting money back on the bus that earned it.
 *
 * WHAT THIS REPAIRS. Until 2026-08-26 the C2B vehicle lookup ran inside
 * BrandScope, which keys on the request host — and every till in the fleet is
 * registered with Safaricom against one host. Confirmations for every brand
 * arrived under a single brand, and the other brand's buses were invisible to
 * the lookup, so the payment was recorded, the transaction row was written, and
 * `vehicle_id` was left null. 5,657 payments worth KES 367,882 on that one day:
 * real fares sitting against no bus.
 *
 * THE PROPERTY THAT MATTERS MOST is that running it twice changes nothing the
 * second time. Summaries are the fleet's daily money totals, and the natural
 * way to write this repair — add each attributed payment to its bus's day — is
 * wrong the moment anyone runs it again. So the repair does not do arithmetic on
 * summaries at all: it attributes, then asks app:generate-vehicle-summaries to
 * RECOMPUTE the affected days from the transactions table. Recompute is
 * assignment, and assignment is idempotent.
 *
 * The second property is that it can never move money BETWEEN buses. It only
 * ever fills a null, never rewrites a vehicle_id that is already set — asserted
 * below, and re-asserted inside the UPDATE itself.
 */
final class AttributeOrphanPaymentsTest extends QueueTestCase
{
    private function busOnShortCode(array $world, string $shortCode, ?string $plate = null): Vehicle
    {
        $bus = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);
        $bus->merchant_short_code = $shortCode;
        if ($plate !== null) {
            $bus->plate = $plate;
        }
        $bus->save();

        return $bus->fresh();
    }

    /** A payment recorded with no bus against it — the shape the bug produced. */
    private function orphan(string $shortCode, float $amount, string $receipt, ?string $when = null): Transaction
    {
        $at = $when ?? now()->toDateTimeString();

        $mpesa = Mpesa::withoutGlobalScopes()->create([
            'TransID' => $receipt,
            'TransAmount' => (string) $amount,
            'TransTime' => $at,
            'MSISDN' => '254712345678',
            'FirstName' => 'Joyce',
            'BusinessShortCode' => $shortCode,
            'TransactionType' => 'Customer Merchant Payment',
        ]);

        return Transaction::withoutGlobalScopes()->create([
            'mpesa_id' => $mpesa->id,
            'vehicle_id' => null,
            'amount' => $amount,
            'trans_date' => $at,
            'summarized' => false,
        ]);
    }

    private function run(array $options = []): void
    {
        $this->artisan('payments:attribute-orphans', $options)->assertExitCode(0);
    }

    #[Test]
    public function an_orphan_on_a_known_till_is_given_its_bus(): void
    {
        $world = $this->makeWorld();
        $bus = $this->busOnShortCode($world, '4560051');
        $txn = $this->orphan('4560051', 150, 'UHVORPH01');

        $this->run(['--write' => true]);

        $this->assertSame($bus->id, (int) $txn->fresh()->vehicle_id);
    }

    #[Test]
    public function a_dry_run_writes_nothing(): void
    {
        // The default. An operator must be able to see the work before it happens.
        $world = $this->makeWorld();
        $this->busOnShortCode($world, '4560051');
        $txn = $this->orphan('4560051', 150, 'UHVORPH01');

        $this->run();

        $this->assertNull($txn->fresh()->vehicle_id, 'without --write nothing may change');
    }

    #[Test]
    public function a_shortcode_shared_by_several_buses_is_refused(): void
    {
        // Production has these — 880100 is shared by 34 vehicles. Picking the
        // first would credit one arbitrary bus with everyone's takings, which is
        // worse than leaving the money unattributed where it can be seen.
        $world = $this->makeWorld();
        $this->busOnShortCode($world, '880100');
        $this->busOnShortCode($world, '880100');
        $txn = $this->orphan('880100', 150, 'UHVAMBIG1');

        $this->run(['--write' => true]);

        $this->assertNull($txn->fresh()->vehicle_id, 'an ambiguous till must never be guessed');
    }

    #[Test]
    public function a_sweep_on_a_collection_account_is_left_alone(): void
    {
        // The SACCO's nightly till-to-bank transfer. Its shortcode belongs to no
        // bus, so there is nothing to attribute and it is not takings.
        $world = $this->makeWorld();
        $this->busOnShortCode($world, '4560051');
        $sweep = $this->orphan('5339736', 24710, 'UHVSWEEP1');

        $this->run(['--write' => true]);

        $this->assertNull($sweep->fresh()->vehicle_id);
    }

    #[Test]
    public function money_already_on_a_bus_is_never_moved(): void
    {
        // The repair fills nulls. It must not be able to re-decide attribution
        // for a payment that already has a bus, whatever the shortcode says.
        $world = $this->makeWorld();
        $rightBus = $this->busOnShortCode($world, '4560051');
        $otherBus = $this->busOnShortCode($world, '9999999');

        $mpesa = Mpesa::withoutGlobalScopes()->create([
            'TransID' => 'UHVSETTLED',
            'TransAmount' => '150',
            'TransTime' => now()->toDateTimeString(),
            'MSISDN' => '254712345678',
            'FirstName' => 'Joyce',
            'BusinessShortCode' => '4560051',
        ]);
        $txn = Transaction::withoutGlobalScopes()->create([
            'mpesa_id' => $mpesa->id,
            'vehicle_id' => $otherBus->id,
            'amount' => 150,
            'trans_date' => now()->toDateTimeString(),
        ]);

        $this->run(['--write' => true]);

        $this->assertSame(
            $otherBus->id,
            (int) $txn->fresh()->vehicle_id,
            'an attributed payment must be left exactly as it is'
        );
        $this->assertNotSame($rightBus->id, (int) $txn->fresh()->vehicle_id);
    }

    #[Test]
    public function the_days_summary_is_rebuilt_to_include_the_recovered_money(): void
    {
        // Attribution alone would leave the transactions screen and the
        // summaries screen disagreeing about the same bus on the same day.
        $world = $this->makeWorld();
        $bus = $this->busOnShortCode($world, '4560051');
        $this->orphan('4560051', 150, 'UHVORPH01');
        $this->orphan('4560051', 50, 'UHVORPH02');

        $this->run(['--write' => true]);

        $summary = Summary::withoutGlobalScopes()
            ->where('vehicle_id', $bus->id)
            ->where('trans_date', now()->toDateString())
            ->first();

        $this->assertNotNull($summary, 'the day must now have a summary row');
        $this->assertSame(200.0, (float) $summary->mpesa_amount);
        $this->assertSame(2, (int) $summary->mpesa_txn);
    }

    #[Test]
    public function running_it_twice_does_not_double_the_days_takings(): void
    {
        // THE ONE THAT MATTERS. A repair that adds to summaries is correct once
        // and wrong forever after. This is why the command recomputes the day
        // rather than incrementing it.
        $world = $this->makeWorld();
        $bus = $this->busOnShortCode($world, '4560051');
        $this->orphan('4560051', 150, 'UHVORPH01');
        $this->orphan('4560051', 50, 'UHVORPH02');

        $this->run(['--write' => true]);
        $this->run(['--write' => true]);

        $summary = Summary::withoutGlobalScopes()
            ->where('vehicle_id', $bus->id)
            ->where('trans_date', now()->toDateString())
            ->first();

        $this->assertSame(200.0, (float) $summary->mpesa_amount, 'a second run must change nothing');
        $this->assertSame(2, (int) $summary->mpesa_txn);
    }

    #[Test]
    public function a_summary_that_already_counted_some_of_the_day_is_not_inflated(): void
    {
        // The real shape on 26 Aug: some of the bus's day attributed correctly
        // and already summarised, the rest orphaned. The rebuilt total must be
        // the day, not the day plus the recovered part.
        $world = $this->makeWorld();
        $bus = $this->busOnShortCode($world, '4560051');

        $settled = Mpesa::withoutGlobalScopes()->create([
            'TransID' => 'UHVOK01', 'TransAmount' => '100', 'TransTime' => now()->toDateTimeString(),
            'MSISDN' => '254712345678', 'FirstName' => 'Ann', 'BusinessShortCode' => '4560051',
        ]);
        Transaction::withoutGlobalScopes()->create([
            'mpesa_id' => $settled->id, 'vehicle_id' => $bus->id, 'amount' => 100,
            'trans_date' => now()->toDateTimeString(), 'summarized' => true,
        ]);
        Summary::withoutGlobalScopes()->create([
            'vehicle_id' => $bus->id, 'trans_date' => now()->toDateString(),
            'mpesa_amount' => 100, 'cash_amount' => 0, 'mpesa_txn' => 1, 'cash_txn' => 0,
        ]);

        $this->orphan('4560051', 150, 'UHVORPH01');

        $this->run(['--write' => true]);

        $summary = Summary::withoutGlobalScopes()
            ->where('vehicle_id', $bus->id)->where('trans_date', now()->toDateString())->first();

        $this->assertSame(250.0, (float) $summary->mpesa_amount, '100 already there plus 150 recovered');
        $this->assertSame(2, (int) $summary->mpesa_txn);
    }

    #[Test]
    public function summarised_is_only_claimed_once_the_summary_exists(): void
    {
        $world = $this->makeWorld();
        $this->busOnShortCode($world, '4560051');
        $txn = $this->orphan('4560051', 150, 'UHVORPH01');

        $this->assertFalse((bool) $txn->summarized);

        $this->run(['--write' => true]);

        $this->assertTrue((bool) $txn->fresh()->summarized);
    }

    #[Test]
    public function the_date_option_confines_the_repair_to_one_day(): void
    {
        // 26 Aug is the day that needs this. Nothing else should move.
        $world = $this->makeWorld();
        $this->busOnShortCode($world, '4560051');

        $target = $this->orphan('4560051', 150, 'UHVDAY01', now()->subDays(3)->toDateTimeString());
        $other = $this->orphan('4560051', 150, 'UHVDAY02', now()->toDateTimeString());

        $this->run(['--date' => now()->subDays(3)->toDateString(), '--write' => true]);

        $this->assertNotNull($target->fresh()->vehicle_id, 'the named day is repaired');
        $this->assertNull($other->fresh()->vehicle_id, 'every other day is left alone');
    }

    #[Test]
    public function it_uses_the_same_rule_as_the_live_callback(): void
    {
        // The repair and the live C2B path both read VehicleByShortCode. If they
        // ever diverged, a repair could credit a bus the callback would not have
        // — which is money on the wrong matatu, found weeks later or never.
        $world = $this->makeWorld();
        $bus = $this->busOnShortCode($world, '4560051');

        $this->assertSame(
            $bus->id,
            VehicleByShortCode::resolve('4560051')?->id
        );
        $this->assertNull(VehicleByShortCode::resolve('5339736'));
        $this->assertNull(VehicleByShortCode::resolve(''));
    }
}
