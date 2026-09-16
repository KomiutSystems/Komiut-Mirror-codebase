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
    public function a_paybill_paid_against_the_bank_account_puts_the_phone_first(): void
    {
        // Co-op's Metrotrans onboarding test, 2026-09-16, verbatim shape: KES 1
        // by paybill 400200 with the SACCO's Co-op account as the reference.
        // The phone and the reference arrive SWAPPED relative to every other
        // shape, and the legacy parser filed the phone as the shortcode and
        // the bank account as the MSISDN.
        $this->postJson($this->url(), [
            'Amount' => '1.0',
            'TransactionDate' => '2026-09-16T16:31:35',
            'Narration' => implode('~', ['UIG6V6K8QO', '254700111222', '01101233137021', 'MPESAC2B_400200', 'MELVIN WANJIKU']),
        ])->assertOk();

        $mpesa = Mpesa::withoutGlobalScopes()->where('TransID', 'UIG6V6K8QO')->first();
        $this->assertNotNull($mpesa);
        $this->assertSame('254700111222', $mpesa->MSISDN, 'the phone is the phone, wherever the bank put it');
        $this->assertSame('01101233137021', $mpesa->BusinessShortCode, 'the account reference is what the bank attributes by');
        $this->assertSame('01101233137021', $mpesa->BillRefNumber);
        $this->assertSame('Pay Bill', $mpesa->TransactionType);
        $this->assertSame('MELVIN', $mpesa->FirstName);
        $this->assertSame('WANJIKU', $mpesa->LastName);
    }

    #[Test]
    public function a_paybill_paid_against_an_alias_keeps_the_reference_first(): void
    {
        // The other paybill shape, also real (alias 1147390 on account
        // 01108066087001): reference at [1], phone at [2]. A SACCO that puts
        // the alias on a bus as its shortcode gets the payment attributed.
        $vehicle = $this->vehicleOn('1147390');

        $this->postJson($this->url(), [
            'Amount' => '100',
            'TransactionDate' => '2026-06-04T12:23:10',
            'Narration' => implode('~', ['UF4686WLMY', '1147390', '254700111222', 'MPESAC2B_400200', 'elizabeth ochieng']),
        ])->assertOk();

        $mpesa = Mpesa::withoutGlobalScopes()->where('TransID', 'UF4686WLMY')->first();
        $this->assertSame('254700111222', $mpesa->MSISDN);
        $this->assertSame('1147390', $mpesa->BusinessShortCode);
        $txn = Transaction::withoutGlobalScopes()->where('mpesa_id', $mpesa->id)->first();
        $this->assertSame($vehicle->id, (int) $txn->vehicle_id);
    }

    #[Test]
    public function the_banks_own_debits_are_acknowledged_and_not_recorded_as_money_in(): void
    {
        // Verbatim from the feed, 2026-09-16 22:13 EAT: the bank recovering a
        // loan instalment from the SACCO's account. Same endpoint, same shape,
        // EventType DEBIT. 203 of these in a month, KES 663,762 -- as much as
        // the fares. A SACCO must not see its loan repayments as takings.
        $this->postJson($this->url(), [
            'AcctNo' => '01109157502300', 'Amount' => '19379.55', 'EventType' => 'DEBIT',
            'TransactionDate' => '2026-09-16T22:12:40', 'TransactionId' => 'CB1034207_16092026_2',
            'Narration' => 'Loan Recovery For01101575023006',
        ])->assertOk()->assertJsonPath('MessageCode', '200');

        $this->assertSame(0, Mpesa::withoutGlobalScopes()->count(), 'a debit is not a payment');
        $this->assertSame(0, Transaction::withoutGlobalScopes()->count());
        $this->assertDatabaseCount('mpesa_logs', 1);
    }

    #[Test]
    public function a_credit_described_in_prose_is_recorded_under_the_banks_own_id(): void
    {
        // An inbound transfer, no tilde, no phone. The narration repeats from
        // month to month and the recorder dedupes on TransID, so the bank's
        // TransactionId is the receipt -- two such credits are two rows.
        foreach (['CB0001_01092026_2', 'CB0002_01102026_2'] as $bankId) {
            $this->postJson($this->url(), [
                'Amount' => '5000', 'EventType' => 'CREDIT', 'TransactionDate' => '2026-09-01T09:00:00',
                'TransactionId' => $bankId, 'Narration' => 'SACCO FLOAT TOP UP',
            ])->assertOk();
        }

        $this->assertSame(2, Mpesa::withoutGlobalScopes()->whereIn('TransID', ['CB0001_01092026_2', 'CB0002_01102026_2'])->count());
    }

    #[Test]
    public function a_fare_with_no_event_type_is_still_a_fare(): void
    {
        // Older posts and the bank's simulator carry no EventType at all.
        $vehicle = $this->vehicleOn('4321078');
        $this->postJson($this->url(), $this->buyGoods('COOP007', '4321078'))->assertOk();
        $this->assertSame(1, Transaction::withoutGlobalScopes()->where('vehicle_id', $vehicle->id)->count());
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
