<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\Mpesa;
use App\Models\Transaction;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Which bus an NCBA confirmation is credited to.
 *
 * NcbaWebhookAuthTest covers who is allowed to post one; this covers where the
 * money lands once they are through the door. Recording a payment is a SYSTEM
 * operation — no authenticated user — but the brand is still active, taken from
 * the `{brand}` URL segment NCBA is provisioned against. Since NCBA posts to ONE
 * registered address, a brand-scoped vehicle lookup could not see a bus on any
 * other brand. That is the same defect that was recording 40.9% of a day's C2B
 * money against vehicle_id NULL (see C2bConfirmationTest).
 */
final class NcbaVehicleAttributionTest extends QueueTestCase
{
    private const URL = '/api/testing/rest/mpesa/confirmation_new';

    /** @param array<string,mixed> $override */
    private function payload(array $override = []): array
    {
        return array_merge([
            'Username' => 'ncbauser', 'Password' => 'ncbapass',
            'TransID' => 'NCBAATTR1', 'TransAmount' => 500,
            'BusinessShortCode' => '7100466',
            'TransTime' => '20260826120000', 'Mobile' => '254700111222',
            'BillRefNumber' => '', 'Name' => 'John Doe',
        ], $override);
    }

    private function vehicleOnShortcode(string $code = '7100466'): \App\Models\Vehicle
    {
        $world = $this->makeWorld();
        $world['vehicle']->merchant_short_code = $code;
        $world['vehicle']->save();

        return $world['vehicle'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ncba.username' => 'ncbauser', 'services.ncba.password' => 'ncbapass']);
    }

    #[Test]
    public function a_payment_for_a_vehicle_on_another_brand_still_reaches_that_bus(): void
    {
        // The confirmation lands on brand `testing` because that is the URL NCBA
        // was given. The bus is on `safiri`. The shortcode the bank sent is what
        // says whose money this is; the brand of the URL is not evidence about it.
        $vehicle = $this->vehicleOnShortcode();
        $vehicle->brand = 'safiri';
        $vehicle->save();

        $this->call('POST', self::URL, $this->payload())->assertOk();

        $mpesa = Mpesa::withoutGlobalScopes()->where('TransID', 'NCBAATTR1')->first();
        $this->assertNotNull($mpesa);

        $txn = Transaction::withoutGlobalScopes()->where('mpesa_id', $mpesa->id)->first();
        $this->assertNotNull($txn);
        $this->assertSame($vehicle->id, (int) $txn->vehicle_id);
    }

    #[Test]
    public function a_shortcode_shared_across_brands_stays_unattributed(): void
    {
        // Searching every brand makes ambiguity more likely, so the multi-match
        // guard has to hold: without it the brand of the receiving URL would
        // silently pick the winner.
        $mine = $this->vehicleOnShortcode();
        $theirs = $this->vehicleOnShortcode();
        $theirs->brand = 'safiri';
        $theirs->save();

        $this->call('POST', self::URL, $this->payload())->assertOk();

        $mpesa = Mpesa::withoutGlobalScopes()->where('TransID', 'NCBAATTR1')->first();
        $this->assertNotNull($mpesa, 'the money must still be recorded — it did arrive');

        $txn = Transaction::withoutGlobalScopes()->where('mpesa_id', $mpesa->id)->first();
        $this->assertNotNull($txn);
        $this->assertNull($txn->vehicle_id);
        $this->assertNotSame($mine->id, $theirs->id);
    }

    #[Test]
    public function the_aggregator_shortcode_still_resolves_by_till_number(): void
    {
        // 880100 is NCBA's own paybill: it identifies the BANK, so the bus is
        // carried in BillRefNumber as its till_number. Dropping the brand filter
        // must not disturb that special case.
        $vehicle = $this->vehicleOnShortcode('880100');
        $vehicle->till_number = '948948';
        $vehicle->brand = 'safiri';
        $vehicle->save();

        $this->call('POST', self::URL, $this->payload([
            'BusinessShortCode' => '880100',
            'BillRefNumber' => '948948',
        ]))->assertOk();

        $mpesa = Mpesa::withoutGlobalScopes()->where('TransID', 'NCBAATTR1')->first();
        $txn = Transaction::withoutGlobalScopes()->where('mpesa_id', $mpesa->id)->first();

        $this->assertNotNull($txn);
        $this->assertSame($vehicle->id, (int) $txn->vehicle_id);
    }
}
