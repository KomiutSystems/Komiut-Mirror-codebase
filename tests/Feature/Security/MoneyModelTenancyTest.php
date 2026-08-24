<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Cash;
use App\Models\Mpesa;
use App\Models\QrcodePayment;
use App\Models\Sacco;
use App\Models\Transaction;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Context;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Cash, Mpesa and QrcodePayment carried NEITHER BelongsToBrand nor
 * BelongsToSacco, so every endpoint reading them was unscoped for every caller:
 * holding 'View Transactions' inside one SACCO returned all 48 SACCOs' money.
 * The three tables are the last money models that were still open.
 *
 * Mpesa is deliberately SACCO-scoped only, never brand-scoped — see the comment
 * on the model. BrandScope applies to unauthenticated requests, and the C2B
 * confirmation webhooks dedupe with a bare Mpesa::where('TransID', ...) under a
 * brand context; scoping that lookup would turn a real payment into a failed
 * one. The last test here pins that asymmetry so it is not "tidied up" later.
 */
final class MoneyModelTenancyTest extends QueueTestCase
{
    protected function tearDown(): void
    {
        Context::flush();
        parent::tearDown();
    }

    private function vehicleIn(Sacco $sacco): Vehicle
    {
        return $this->makeVehicle($sacco, $this->makeUser([], $sacco), $this->makeSeat());
    }

    private function cashFor(Vehicle $vehicle, string $transId): Cash
    {
        return Cash::create([
            'trans_id' => $transId,
            'vehicle_id' => $vehicle->id,
            'firstname' => 'Cash', 'lastname' => 'Payer',
            'recieved_amount' => 200, 'fare_amount' => 200, 'luggage_amount' => 0,
            'change_amount' => 0, 'total_amount' => 200,
            'trans_date' => now(),
        ]);
    }

    private function mpesaFor(Vehicle $vehicle, string $transId): Mpesa
    {
        $mpesa = Mpesa::create([
            'TransID' => $transId,
            'MSISDN' => '254700111222',
            'TransAmount' => 150,
            'TransTime' => now(),
            'FirstName' => 'Mpesa', 'LastName' => 'Payer',
            'BusinessShortCode' => '5557936',
        ]);

        Transaction::create([
            'mpesa_id' => $mpesa->id,
            'vehicle_id' => $vehicle->id,
            'amount' => 150,
            'trans_date' => now(),
        ]);

        return $mpesa;
    }

    private function qrFor(Vehicle $vehicle, float $amount = 90): QrcodePayment
    {
        return QrcodePayment::create([
            'vehicle_id' => $vehicle->id,
            'amount' => $amount,
            'status' => true,
        ]);
    }

    #[Test]
    public function the_cash_list_does_not_return_another_saccos_takings(): void
    {
        $mine = $this->makeSacco();
        $theirs = $this->makeSacco();
        $this->cashFor($this->vehicleIn($mine), 'MINE-CASH');
        $this->cashFor($this->vehicleIn($theirs), 'THEIRS-CASH');

        Sanctum::actingAs($this->makeUser(['View Transactions'], $mine));

        $rows = $this->getJson('/api/v1/auth/transactions/cash')->assertOk()->json('cash');
        $ids = array_column($rows, 'trans_id');

        $this->assertContains('MINE-CASH', $ids);
        $this->assertNotContains('THEIRS-CASH', $ids, 'Cash had no tenancy at all — this endpoint returned every SACCO.');
    }

    #[Test]
    public function the_mpesa_list_does_not_return_another_saccos_payments(): void
    {
        $mine = $this->makeSacco();
        $theirs = $this->makeSacco();
        $this->mpesaFor($this->vehicleIn($mine), 'MINE-MPESA');
        $this->mpesaFor($this->vehicleIn($theirs), 'THEIRS-MPESA');

        Sanctum::actingAs($this->makeUser(['View Transactions'], $mine));

        $rows = $this->getJson('/api/v1/auth/transactions/mpesa')->assertOk()->json('mpesa');
        $ids = array_column($rows, 'TransID');

        $this->assertContains('MINE-MPESA', $ids);
        $this->assertNotContains('THEIRS-MPESA', $ids);
    }

    #[Test]
    public function the_qrcode_payment_list_does_not_return_another_saccos_payments(): void
    {
        $mine = $this->makeSacco();
        $theirs = $this->makeSacco();
        $myVehicle = $this->vehicleIn($mine);
        $theirVehicle = $this->vehicleIn($theirs);
        $this->qrFor($myVehicle, 90);
        $this->qrFor($theirVehicle, 310);

        Sanctum::actingAs($this->makeUser(['View Transactions'], $mine));

        $rows = $this->getJson('/api/v1/auth/qrcode/payments')->assertOk()->json('payments');
        $vehicleIds = array_column($rows, 'vehicle_id');

        $this->assertContains($myVehicle->id, $vehicleIds);
        $this->assertNotContains($theirVehicle->id, $vehicleIds);
    }

    #[Test]
    public function a_saccos_own_money_rows_are_still_reachable_by_the_model(): void
    {
        // The scopes must narrow, not empty the screen: the same three reads a
        // SACCO makes every day still resolve.
        $sacco = $this->makeSacco();
        $vehicle = $this->vehicleIn($sacco);
        $cash = $this->cashFor($vehicle, 'OWN-CASH');
        $mpesa = $this->mpesaFor($vehicle, 'OWN-MPESA');
        $qr = $this->qrFor($vehicle);

        $this->actingAs($this->makeUser(['View Transactions'], $sacco));

        $this->assertNotNull(Cash::find($cash->id));
        $this->assertNotNull(Mpesa::find($mpesa->id));
        $this->assertNotNull(QrcodePayment::find($qr->id));
    }

    #[Test]
    public function cash_and_qrcode_payments_are_confined_to_the_current_brand(): void
    {
        // Both reach `brand` through their vehicle, the same path Transaction
        // and Summary use.
        $komiut = Sacco::create(['name' => 'K', 'status' => 1, 'brand' => 'komiut']);
        $safiri = Sacco::create(['name' => 'S', 'status' => 1, 'brand' => 'safiri']);
        $komiutVehicle = $this->vehicleIn($komiut);
        $safiriVehicle = $this->vehicleIn($safiri);

        // Pin the fixture itself: BelongsToBrand stamps `brand` on create only
        // for models that OWN the column and only when Context carries a brand.
        // If either sacco/vehicle came out on the wrong brand the assertions
        // below would pass for the wrong reason.
        $this->assertSame('safiri', $safiri->fresh()->brand);
        $this->assertSame('safiri', $safiriVehicle->fresh()->brand);
        $this->assertSame('komiut', $komiutVehicle->fresh()->brand);

        $mine = $this->cashFor($komiutVehicle, 'K-CASH');
        $theirs = $this->cashFor($safiriVehicle, 'S-CASH');
        $myQr = $this->qrFor($komiutVehicle);
        $theirQr = $this->qrFor($safiriVehicle);

        Context::add('brand', 'komiut');

        $this->assertNotNull(Cash::find($mine->id));
        $this->assertNull(Cash::find($theirs->id), 'The komiut app must not read safiri cash.');
        $this->assertNotNull(QrcodePayment::find($myQr->id));
        $this->assertNull(QrcodePayment::find($theirQr->id));
    }

    #[Test]
    public function mpesa_stays_readable_without_an_authenticated_user_even_under_a_brand(): void
    {
        // This is the webhook shape: brand Context is set by `brand.route`, but
        // nobody is authenticated. The C2B confirmation handlers dedupe with
        // Mpesa::where('TransID', ...)->first(), and that lookup MUST still find
        // an existing row whose transaction has no vehicle yet — otherwise they
        // insert a duplicate, violate the unique index on TransID and record a
        // payment that really arrived as failed.
        $orphan = Mpesa::create([
            'TransID' => 'WEBHOOK-RETRY-1',
            'MSISDN' => '254700111222',
            'TransAmount' => 40,
            'TransTime' => now(),
            'FirstName' => 'Unmatched', 'LastName' => 'Payer',
            'BusinessShortCode' => '5557936',
        ]);

        Context::add('brand', 'komiut');

        $this->assertNotNull(
            Mpesa::where('TransID', 'WEBHOOK-RETRY-1')->first(),
            'Mpesa must not be brand-scoped: it would break C2B dedupe and lose live payments.'
        );
        $this->assertSame($orphan->id, Mpesa::where('TransID', 'WEBHOOK-RETRY-1')->first()->id);
    }

    #[Test]
    public function unattributed_mpesa_rows_belong_to_no_sacco(): void
    {
        // Money with no transaction, or a transaction that never resolved a
        // vehicle, is a reconciliation problem for the super console — not
        // something to hand to whichever SACCO happens to be looking.
        $sacco = $this->makeSacco();
        Mpesa::create([
            'TransID' => 'UNATTRIBUTED-1',
            'MSISDN' => '254700111222',
            'TransAmount' => 40,
            'TransTime' => now(),
            'FirstName' => 'Unmatched', 'LastName' => 'Payer',
            'BusinessShortCode' => '5557936',
        ]);

        $this->actingAs($this->makeUser(['View Transactions'], $sacco));

        $this->assertNull(Mpesa::where('TransID', 'UNATTRIBUTED-1')->first());
    }
}
