<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\Mpesa;
use App\Models\MpesaPaymentSetting;
use App\Models\Sacco;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The M-Pesa payments web dashboard: per-SACCO settings CRUD (credentials
 * encrypted, write-only, SACCO-scoped) and the tills / stats / transaction reads
 * (SACCO-scoped, no cross-tenant leak).
 */
final class MpesaPaymentsDashboardTest extends QueueTestCase
{
    private function admin(Sacco $sacco, array $permissions = ['Add Payment Settings', 'Edit Payment Settings', 'View Payment Settings', 'View Transactions']): User
    {
        return $this->makeUser($permissions, $sacco);
    }

    private function vehicleFor(Sacco $sacco, string $plate, ?string $till = '111', ?string $merchant = '222'): Vehicle
    {
        $vehicle = $this->makeVehicle($sacco, $this->makeUser(['View Payment Settings', 'View Transactions'], $sacco), $this->makeSeat());
        $vehicle->update(['plate' => $plate, 'till_number' => $till, 'merchant_short_code' => $merchant]);

        return $vehicle;
    }

    private function payment(Vehicle $vehicle, string $transId, float $amount): Mpesa
    {
        $mpesa = Mpesa::create([
            'TransID' => $transId,
            'MSISDN' => '254700111222',
            'TransAmount' => $amount,
            'TransTime' => now(),
            'FirstName' => 'Test',
            'LastName' => 'Payer',
            'BusinessShortCode' => '5557936',
            'BillRefNumber' => $vehicle->till_number,
            'TransactionType' => 'Pay Bill',
        ]);
        Transaction::create([
            'mpesa_id' => $mpesa->id,
            'vehicle_id' => $vehicle->id,
            'amount' => $amount,
            'trans_date' => now(),
        ]);

        return $mpesa;
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'business_short_code' => '174379',
            'paybill' => '5557936',
            'payment_mode' => 'paybill',
            'status' => true,
            'consumer_key' => 'CK-live-123',
            'consumer_secret' => 'CS-live-secret',
            'pass_key' => 'PK-live-passkey',
        ], $overrides);
    }

    #[Test]
    public function saving_settings_stores_the_credentials_encrypted(): void
    {
        $sacco = $this->makeSacco();
        Sanctum::actingAs($this->admin($sacco));

        $this->postJson('/api/v1/auth/mpesa/settings', $this->validPayload())->assertOk();

        $raw = DB::table('mpesa_payment_settings')->where('sacco_id', $sacco->id)->first();
        $this->assertNotSame('CS-live-secret', $raw->consumer_secret, 'The secret must not be stored in plaintext.');
        $this->assertNotSame('PK-live-passkey', $raw->pass_key, 'The passkey must not be stored in plaintext.');

        // But the model transparently decrypts for the payment path.
        $setting = MpesaPaymentSetting::where('sacco_id', $sacco->id)->first();
        $this->assertSame('CS-live-secret', $setting->consumer_secret);
        $this->assertSame('PK-live-passkey', $setting->pass_key);
        $this->assertSame('CustomerPayBillOnline', $setting->payment_mode);
        $this->assertTrue((bool) $setting->is_live, 'Settings are always live — there is no sandbox.');
    }

    #[Test]
    public function the_settings_read_never_returns_secrets(): void
    {
        $sacco = $this->makeSacco();
        Sanctum::actingAs($this->admin($sacco));
        $this->postJson('/api/v1/auth/mpesa/settings', $this->validPayload())->assertOk();

        $body = $this->getJson('/api/v1/auth/mpesa/settings')->assertOk()->json('setting');

        $this->assertArrayNotHasKey('consumer_secret', $body);
        $this->assertArrayNotHasKey('pass_key', $body);
        $this->assertArrayNotHasKey('consumer_key', $body);
        $this->assertTrue($body['consumer_secret_set']);
        $this->assertSame('5557936', $body['paybill']);
    }

    #[Test]
    public function editing_without_resending_a_secret_keeps_it(): void
    {
        $sacco = $this->makeSacco();
        Sanctum::actingAs($this->admin($sacco));
        $this->postJson('/api/v1/auth/mpesa/settings', $this->validPayload())->assertOk();

        // Update only the short code — the form did not echo the secret back.
        $this->postJson('/api/v1/auth/mpesa/settings', [
            'business_short_code' => '999999',
            'payment_mode' => 'buygoods',
        ])->assertOk();

        $setting = MpesaPaymentSetting::where('sacco_id', $sacco->id)->first();
        $this->assertSame('999999', $setting->business_short_code);
        $this->assertSame('CustomerBuyGoodsOnline', $setting->payment_mode);
        $this->assertSame('CS-live-secret', $setting->consumer_secret, 'The secret must survive an edit that omits it.');
        $this->assertSame(1, MpesaPaymentSetting::where('sacco_id', $sacco->id)->count(), 'Upsert must not create a duplicate.');
    }

    #[Test]
    public function first_save_requires_every_credential(): void
    {
        $sacco = $this->makeSacco();
        Sanctum::actingAs($this->admin($sacco));

        $this->postJson('/api/v1/auth/mpesa/settings', [
            'business_short_code' => '174379',
            'payment_mode' => 'paybill',
        ])->assertStatus(400);
    }

    #[Test]
    public function a_user_without_permission_cannot_write_settings(): void
    {
        $sacco = $this->makeSacco();
        Sanctum::actingAs($this->admin($sacco, permissions: []));

        $this->postJson('/api/v1/auth/mpesa/settings', $this->validPayload())->assertStatus(403);
    }

    #[Test]
    public function one_sacco_cannot_read_another_saccos_settings(): void
    {
        $mine = $this->makeSacco();
        $theirs = $this->makeSacco();
        MpesaPaymentSetting::create([
            'sacco_id' => $theirs->id, 'consumer_key' => 'x', 'consumer_secret' => 'y',
            'pass_key' => 'z', 'business_short_code' => '111', 'payment_mode' => 'CustomerPayBillOnline',
            'is_live' => true, 'status' => true,
        ]);

        Sanctum::actingAs($this->admin($mine));

        // My SACCO has none — theirs must not leak through.
        $this->getJson('/api/v1/auth/mpesa/settings')->assertOk()->assertJsonPath('setting', null);
    }

    #[Test]
    public function the_mpesa_transaction_list_is_confined_to_the_callers_sacco(): void
    {
        $mine = $this->makeSacco();
        $theirs = $this->makeSacco();
        $myVehicle = $this->vehicleFor($mine, 'KDA001A');
        $theirVehicle = $this->vehicleFor($theirs, 'KDB002B');
        $this->payment($myVehicle, 'MINE-TX', 50);
        $this->payment($theirVehicle, 'THEIRS-TX', 70);

        Sanctum::actingAs($this->admin($mine));
        $rows = $this->getJson('/api/v1/auth/transactions/mpesa')->assertOk()->json('mpesa');

        $ids = array_column($rows, 'TransID');
        $this->assertContains('MINE-TX', $ids);
        $this->assertNotContains('THEIRS-TX', $ids, 'A SACCO admin must not see another SACCO\'s payments.');
    }

    #[Test]
    public function tills_lists_only_configured_vehicles_in_the_sacco(): void
    {
        $mine = $this->makeSacco();
        $theirs = $this->makeSacco();
        MpesaPaymentSetting::create([
            'sacco_id' => $mine->id, 'consumer_key' => 'x', 'consumer_secret' => 'y', 'pass_key' => 'z',
            'business_short_code' => '111', 'paybill' => '5557936', 'payment_mode' => 'CustomerPayBillOnline',
            'is_live' => true, 'status' => true,
        ]);
        $this->vehicleFor($mine, 'KDA001A', till: '4321087', merchant: '4321075');
        $this->vehicleFor($mine, 'KDA002B', till: null, merchant: null); // not a till — excluded
        $this->vehicleFor($theirs, 'KDB003C', till: '9999');            // other sacco — excluded

        Sanctum::actingAs($this->admin($mine));
        $tills = $this->getJson('/api/v1/auth/mpesa/tills')->assertOk()->json('tills');

        $plates = array_column($tills, 'plate');
        $this->assertSame(['KDA001A'], $plates);
        $this->assertSame('5557936', $tills[0]['paybill'], 'Each till shows its SACCO paybill.');
        $this->assertSame('4321087', $tills[0]['till_number']);
    }

    #[Test]
    public function stats_returns_scoped_tiles(): void
    {
        $mine = $this->makeSacco();
        $theirs = $this->makeSacco();
        $myVehicle = $this->vehicleFor($mine, 'KDA001A');
        $theirVehicle = $this->vehicleFor($theirs, 'KDB002B');
        $this->payment($myVehicle, 'MINE-TX', 80);
        $this->payment($theirVehicle, 'THEIRS-TX', 500);

        Sanctum::actingAs($this->admin($mine));
        $stats = $this->getJson('/api/v1/auth/mpesa/stats')->assertOk()->json();

        $this->assertSame(80.0, (float) $stats['mpesa_today'], 'Today total counts only this SACCO.');
        $this->assertSame(1, $stats['tills_count']);
        $this->assertContains('MINE-TX', array_column($stats['recent_transactions'], 'trans_id'));
        $this->assertNotContains('THEIRS-TX', array_column($stats['recent_transactions'], 'trans_id'));
    }

    #[Test]
    public function a_row_written_before_encryption_is_read_as_plaintext(): void
    {
        // Simulate a legacy row: raw plaintext straight into the column.
        $sacco = $this->makeSacco();
        DB::table('mpesa_payment_settings')->insert([
            'sacco_id' => $sacco->id,
            'consumer_key' => 'legacy-ck',
            'consumer_secret' => 'legacy-plaintext-secret',
            'pass_key' => 'legacy-passkey',
            'business_short_code' => '174379',
            'payment_mode' => 'CustomerPayBillOnline',
            'is_live' => true,
            'status' => true,
        ]);

        // The cast must return it verbatim, never throw a DecryptException.
        $setting = MpesaPaymentSetting::where('sacco_id', $sacco->id)->first();
        $this->assertSame('legacy-plaintext-secret', $setting->consumer_secret);
    }

    #[Test]
    public function the_model_never_serializes_its_secrets(): void
    {
        $setting = MpesaPaymentSetting::create([
            'sacco_id' => $this->makeSacco()->id, 'consumer_key' => 'x', 'consumer_secret' => 'y', 'pass_key' => 'z',
            'business_short_code' => '111', 'payment_mode' => 'CustomerPayBillOnline', 'is_live' => true, 'status' => true,
        ]);

        $array = $setting->toArray();
        $this->assertArrayNotHasKey('consumer_secret', $array);
        $this->assertArrayNotHasKey('consumer_key', $array);
        $this->assertArrayNotHasKey('pass_key', $array);
    }
}
