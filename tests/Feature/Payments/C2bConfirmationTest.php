<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\Mpesa;
use App\Models\Summary;
use App\Models\Transaction;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Safaricom C2B on the per-till URL the fleet is registered against:
 * /api/confirmation/{mpesa_setting_id}.
 *
 * This is the endpoint a till points at when it is migrated off the legacy
 * payment tier, so these tests are the acceptance criteria for that move: the
 * money has to reach `transactions` and the daily `summaries` by the same path an
 * NCBA confirmation takes, the ack has to match what Safaricom already accepts,
 * and a payload we cannot attribute must never be silently credited to a bus.
 */
final class C2bConfirmationTest extends QueueTestCase
{
    private const URL = '/api/confirmation/4';

    /** @param array<string,mixed> $override */
    private function payload(array $override = []): array
    {
        return array_merge([
            'TransactionType' => 'Customer Merchant Payment',
            'TransID' => 'UHQQ349A09',
            'TransTime' => '20260826000238',
            'TransAmount' => '50.00',
            'BusinessShortCode' => '7100466',
            'BillRefNumber' => '',
            'InvoiceNumber' => '',
            'OrgAccountBalance' => '1000.00',
            'ThirdPartyTransID' => '',
            'MSISDN' => '254700111222',
            'FirstName' => 'WANJIKU',
            'MiddleName' => '',
            'LastName' => 'KAMAU',
        ], $override);
    }

    private function vehicleOnShortcode(string $code = '7100466'): \App\Models\Vehicle
    {
        $world = $this->makeWorld();
        $world['vehicle']->merchant_short_code = $code;
        $world['vehicle']->save();

        return $world['vehicle'];
    }

    #[Test]
    public function a_confirmation_reaches_transactions_and_the_daily_summary(): void
    {
        $vehicle = $this->vehicleOnShortcode();

        $this->call('POST', self::URL, $this->payload())->assertOk();

        $this->assertDatabaseHas('mpesas', ['TransID' => 'UHQQ349A09', 'BusinessShortCode' => '7100466']);

        $mpesa = Mpesa::withoutGlobalScopes()->where('TransID', 'UHQQ349A09')->first();
        $txn = Transaction::withoutGlobalScopes()->where('mpesa_id', $mpesa->id)->first();

        $this->assertNotNull($txn, 'the payment must produce a transaction, or it is invisible to takings');
        $this->assertSame($vehicle->id, (int) $txn->vehicle_id);
        $this->assertSame(50.0, (float) $txn->amount);

        $summary = Summary::withoutGlobalScopes()->where('vehicle_id', $vehicle->id)->first();
        $this->assertNotNull($summary, 'the fare must roll into the vehicle day summary');
        $this->assertSame(50.0, (float) $summary->mpesa_amount);
        $this->assertSame(1, (int) $summary->mpesa_txn);
    }

    #[Test]
    public function it_records_which_registered_till_delivered_the_payment(): void
    {
        // The {id} is how "which tills have moved" stays a GROUP BY instead of
        // 178 questions to Safaricom.
        $this->vehicleOnShortcode();

        $this->call('POST', self::URL, $this->payload())->assertOk();

        $this->assertDatabaseHas('mpesas', ['TransID' => 'UHQQ349A09', 'mpesa_setting_id' => 4]);
    }

    #[Test]
    public function the_ack_matches_what_safaricom_already_accepts(): void
    {
        $this->vehicleOnShortcode();

        $response = $this->call('POST', self::URL, $this->payload());

        $response->assertOk();
        $this->assertStringContainsString('text/xml', (string) $response->headers->get('Content-Type'));
        $this->assertSame('{"C2BPaymentConfirmationResult":"Success"}', $response->getContent());
    }

    #[Test]
    public function safaricom_retrying_the_same_receipt_does_not_bank_it_twice(): void
    {
        $vehicle = $this->vehicleOnShortcode();

        $this->call('POST', self::URL, $this->payload())->assertOk();
        $this->call('POST', self::URL, $this->payload())->assertOk();

        $this->assertSame(1, Mpesa::withoutGlobalScopes()->where('TransID', 'UHQQ349A09')->count());
        $this->assertSame(1, Transaction::withoutGlobalScopes()->where('vehicle_id', $vehicle->id)->count());

        $summary = Summary::withoutGlobalScopes()->where('vehicle_id', $vehicle->id)->first();
        $this->assertSame(50.0, (float) $summary->mpesa_amount, 'a retry must not double the takings');
    }

    #[Test]
    public function an_ambiguous_shortcode_is_never_credited_to_an_arbitrary_bus(): void
    {
        // Production has 34 vehicles sharing merchant_short_code 880100. Resolving
        // that with ->first() silently gave one of them everyone else's money.
        $a = $this->vehicleOnShortcode('880100');
        $b = $this->vehicleOnShortcode('880100');

        $this->call('POST', self::URL, $this->payload(['BusinessShortCode' => '880100']))->assertOk();

        $mpesa = Mpesa::withoutGlobalScopes()->where('TransID', 'UHQQ349A09')->first();
        $this->assertNotNull($mpesa, 'the money must still be recorded — it did arrive');

        $txn = Transaction::withoutGlobalScopes()->where('mpesa_id', $mpesa->id)->first();
        $this->assertNotNull($txn);
        $this->assertNull($txn->vehicle_id, 'an ambiguous shortcode must stay unattributed, not be guessed');

        $this->assertSame(0, Summary::withoutGlobalScopes()->whereIn('vehicle_id', [$a->id, $b->id])->count());
    }

    #[Test]
    public function a_payment_for_a_vehicle_on_another_brand_still_reaches_that_bus(): void
    {
        // THE 41% BUG. Every till is registered with Safaricom against the single
        // `config('app.url')` host, so every confirmation for the whole fleet
        // arrives under ONE brand — `testing` here. BrandScope then hid every
        // vehicle belonging to any other brand, and the payment recorded with
        // vehicle_id NULL. Measured in production 2026-08-26: all 54 vehicles on
        // brand `safiri` were landing NULL — 2,576 transactions, KES 159,947,
        // 40.9% of the day.
        //
        // Whose money this is, is decided by the shortcode Safaricom sends. The
        // brand of the host the callback landed on is not evidence about that.
        $vehicle = $this->vehicleOnShortcode();
        $vehicle->brand = 'safiri';
        $vehicle->save();

        $this->call('POST', self::URL, $this->payload())->assertOk();

        $mpesa = Mpesa::withoutGlobalScopes()->where('TransID', 'UHQQ349A09')->first();
        $txn = Transaction::withoutGlobalScopes()->where('mpesa_id', $mpesa->id)->first();

        $this->assertNotNull($txn);
        $this->assertSame(
            $vehicle->id,
            (int) $txn->vehicle_id,
            'a confirmation must reach its bus whatever brand host it happened to land on'
        );

        $summary = Summary::withoutGlobalScopes()->where('vehicle_id', $vehicle->id)->first();
        $this->assertNotNull($summary, 'and the fare must roll into that vehicle day summary');
        $this->assertSame(50.0, (float) $summary->mpesa_amount);
    }

    #[Test]
    public function a_shortcode_shared_across_brands_is_not_credited_to_the_hosts_brand(): void
    {
        // The other edge of the same change. Searching every brand makes
        // ambiguity MORE likely, so the multi-match guard matters more, not less:
        // two vehicles on different brands sharing a shortcode used to LOOK
        // unambiguous because the scope hid one of them, which meant the brand of
        // the receiving host silently decided who got paid.
        $mine = $this->vehicleOnShortcode();
        $theirs = $this->vehicleOnShortcode();
        $theirs->brand = 'safiri';
        $theirs->save();

        $this->call('POST', self::URL, $this->payload())->assertOk();

        $mpesa = Mpesa::withoutGlobalScopes()->where('TransID', 'UHQQ349A09')->first();
        $this->assertNotNull($mpesa, 'the money must still be recorded — it did arrive');

        $txn = Transaction::withoutGlobalScopes()->where('mpesa_id', $mpesa->id)->first();
        $this->assertNotNull($txn);
        $this->assertNull(
            $txn->vehicle_id,
            'a cross-brand collision must stay unattributed, not default to the request brand'
        );

        $this->assertSame(0, Summary::withoutGlobalScopes()->whereIn('vehicle_id', [$mine->id, $theirs->id])->count());
    }

    #[Test]
    public function an_unknown_shortcode_is_still_recorded_and_acked(): void
    {
        // Money we cannot place is money we still received. Losing it is worse
        // than holding it unattributed, and a non-2xx would make Safaricom retry.
        $this->call('POST', self::URL, $this->payload(['BusinessShortCode' => '9999999']))
            ->assertOk()
            ->assertSee('Success');

        $this->assertDatabaseHas('mpesas', ['TransID' => 'UHQQ349A09']);
    }

    #[Test]
    public function every_confirmation_leaves_a_raw_trace(): void
    {
        // mpesa_logs is how we prove, during a till migration, that traffic has
        // actually started arriving here.
        $this->vehicleOnShortcode();

        $this->call('POST', self::URL, $this->payload())->assertOk();

        $this->assertDatabaseHas('mpesa_logs', ['trans_id' => 'UHQQ349A09']);
    }

    #[Test]
    public function the_validation_url_accepts(): void
    {
        // An unanswered ValidationURL makes Safaricom fail the transaction
        // outright, so the registered pair must both respond.
        $this->call('POST', '/api/validation/4', $this->payload())
            ->assertOk()
            ->assertSee('Accepted');
    }

    #[Test]
    public function the_id_segment_cannot_select_whose_money_this_is(): void
    {
        // Attribution is by BusinessShortCode alone. If the URL could choose the
        // vehicle, the URL would be a way to redirect someone's takings.
        $vehicle = $this->vehicleOnShortcode();

        $this->call('POST', '/api/confirmation/99999', $this->payload())->assertOk();

        $mpesa = Mpesa::withoutGlobalScopes()->where('TransID', 'UHQQ349A09')->first();
        $txn = Transaction::withoutGlobalScopes()->where('mpesa_id', $mpesa->id)->first();

        $this->assertSame($vehicle->id, (int) $txn->vehicle_id);
        $this->assertSame(99999, (int) $mpesa->mpesa_setting_id);
    }
}
