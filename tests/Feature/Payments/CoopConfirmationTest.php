<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\Mpesa;
use App\Models\Summary;
use App\Models\Transaction;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Context;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Co-op's C2B confirmation endpoint, POST /api/{brand}/coop/mpesa.
 *
 * This endpoint had NO test coverage at all — CoopSettlementAttributionTest,
 * despite the name, only exercises the app:attribute-coop-settlements command.
 * So these are the first assertions on a path that has been taking real money.
 *
 * Co-op is the odd one out among the three ingestion routes: it packs the whole
 * payment into a single tilde-delimited Narration string rather than sending
 * named fields, and it has two shapes — buy-goods, and paybill via the shared
 * 400200 which inserts an MPESAC2B_<paybill> tag that shifts the payer name by
 * one position. Both are covered here, because the parse is the only Co-op
 * specific thing left in the controller.
 */
final class CoopConfirmationTest extends QueueTestCase
{
    private function url(): string
    {
        // {brand} route segment — the same shape Co-op is registered against.
        return '/api/testing/coop/mpesa';
    }

    /** Buy-goods narration: transId~shortcode~phone~name~date */
    private function buyGoods(string $transId, string $shortCode, string $name = 'JOYCE WANJIKU MWANGI'): array
    {
        return [
            'Amount' => '50',
            'TransactionDate' => '2026-08-26+07:53:37',
            'Narration' => implode('~', [$transId, $shortCode, '254700111222', $name, '2026-08-26 07:53:37']),
        ];
    }

    private function vehicleOn(string $shortCode): Vehicle
    {
        $world = $this->makeWorld();
        $world['vehicle']->forceFill(['merchant_short_code' => $shortCode])->save();

        return $world['vehicle'];
    }

    #[Test]
    public function a_coop_payment_reaches_transactions_and_the_daily_summary(): void
    {
        $vehicle = $this->vehicleOn('4321075');

        $this->postJson($this->url(), $this->buyGoods('COOP001', '4321075'))->assertOk();

        $mpesa = Mpesa::withoutGlobalScopes()->where('TransID', 'COOP001')->first();
        $this->assertNotNull($mpesa);
        $this->assertSame('4321075', $mpesa->BusinessShortCode);
        $this->assertSame('Buy Goods Online', $mpesa->TransactionType);

        $txn = Transaction::withoutGlobalScopes()->where('mpesa_id', $mpesa->id)->first();
        $this->assertNotNull($txn, 'the payment must produce a transaction or it is invisible to takings');
        $this->assertSame($vehicle->id, (int) $txn->vehicle_id);

        $summary = Summary::withoutGlobalScopes()->where('vehicle_id', $vehicle->id)->first();
        $this->assertNotNull($summary);
        $this->assertEqualsWithDelta(50.0, (float) $summary->mpesa_amount, 0.001);
        $this->assertSame(1, (int) $summary->mpesa_txn);
    }

    #[Test]
    public function a_vehicle_on_another_brand_is_still_attributed(): void
    {
        // THE 40.9% BUG. Recording is a system operation, but BrandScope keys on
        // Context, which the brand.route middleware sets from the {brand} URL
        // segment. Every till in the fleet is registered against one host, so a
        // scoped lookup made every other brand's buses invisible and their money
        // landed with vehicle_id NULL.
        $vehicle = $this->vehicleOn('5551234');
        $vehicle->forceFill(['brand' => 'otherbrand'])->save();

        Context::add('brand', 'testing');

        $this->postJson($this->url(), $this->buyGoods('COOP002', '5551234'))->assertOk();

        $mpesa = Mpesa::withoutGlobalScopes()->where('TransID', 'COOP002')->first();
        $txn = Transaction::withoutGlobalScopes()->where('mpesa_id', $mpesa->id)->first();

        $this->assertSame(
            $vehicle->id,
            (int) $txn->vehicle_id,
            'a payment must reach its bus regardless of which brand the callback arrived under'
        );
    }

    #[Test]
    public function an_ambiguous_shortcode_is_never_credited_to_an_arbitrary_bus(): void
    {
        // Production has 34 vehicles sharing merchant_short_code 880100.
        $a = $this->vehicleOn('880100');
        $b = $this->vehicleOn('880100');

        $this->postJson($this->url(), $this->buyGoods('COOP003', '880100'))->assertOk();

        $mpesa = Mpesa::withoutGlobalScopes()->where('TransID', 'COOP003')->first();
        $this->assertNotNull($mpesa, 'the money still arrived and must still be recorded');

        $txn = Transaction::withoutGlobalScopes()->where('mpesa_id', $mpesa->id)->first();
        $this->assertNotNull($txn);
        $this->assertNull($txn->vehicle_id, 'an ambiguous shortcode must stay unattributed, not be guessed');

        $this->assertSame(0, Summary::withoutGlobalScopes()->whereIn('vehicle_id', [$a->id, $b->id])->count());
    }

    #[Test]
    public function coop_retrying_the_same_narration_does_not_bank_it_twice(): void
    {
        $vehicle = $this->vehicleOn('4321076');

        $this->postJson($this->url(), $this->buyGoods('COOP004', '4321076'))->assertOk();
        $this->postJson($this->url(), $this->buyGoods('COOP004', '4321076'))->assertOk();

        $this->assertSame(1, Mpesa::withoutGlobalScopes()->where('TransID', 'COOP004')->count());
        $this->assertSame(1, Transaction::withoutGlobalScopes()->where('vehicle_id', $vehicle->id)->count());

        $summary = Summary::withoutGlobalScopes()->where('vehicle_id', $vehicle->id)->first();
        $this->assertEqualsWithDelta(50.0, (float) $summary->mpesa_amount, 0.001, 'a retry must not double the takings');
    }

    #[Test]
    public function the_paybill_narration_shape_shifts_the_payer_name(): void
    {
        // Paybill through Co-op's shared 400200 inserts MPESAC2B_<paybill> at [3],
        // so the name moves to [4] and the shortcode doubles as the bill ref.
        $vehicle = $this->vehicleOn('4321077');

        $this->postJson($this->url(), [
            'Amount' => '120',
            'TransactionDate' => '2026-08-26+08:10:00',
            'Narration' => implode('~', ['COOP005', '4321077', '254700111222', 'MPESAC2B_400200', 'OTIENO ODHIAMBO']),
        ])->assertOk();

        $mpesa = Mpesa::withoutGlobalScopes()->where('TransID', 'COOP005')->first();
        $this->assertNotNull($mpesa);
        $this->assertSame('Pay Bill', $mpesa->TransactionType);
        $this->assertSame('OTIENO', $mpesa->FirstName, 'the payer name sits at [4] on the paybill shape');
        $this->assertSame('4321077', $mpesa->BillRefNumber);

        $txn = Transaction::withoutGlobalScopes()->where('mpesa_id', $mpesa->id)->first();
        $this->assertSame($vehicle->id, (int) $txn->vehicle_id);
    }

    #[Test]
    public function an_unknown_shortcode_is_still_recorded_and_acked(): void
    {
        // Money we cannot place is money we still received, and Co-op is told the
        // same thing either way — a non-2xx buys a retry storm, not a recovery.
        $this->postJson($this->url(), $this->buyGoods('COOP006', '9999999'))
            ->assertOk()
            ->assertJsonPath('MessageCode', '200');

        $this->assertDatabaseHas('mpesas', ['TransID' => 'COOP006']);
    }

    #[Test]
    public function a_malformed_amount_is_rejected_before_anything_is_written(): void
    {
        $this->postJson($this->url(), ['Amount' => '0', 'TransactionDate' => '2026-08-26+08:00:00', 'Narration' => 'X~1~2~3'])
            ->assertStatus(400);

        $this->assertDatabaseMissing('mpesas', ['TransID' => 'X']);
    }
}
