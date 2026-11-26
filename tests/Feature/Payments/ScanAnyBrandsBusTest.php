<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Brands\BrandRegistry;
use App\Enums\UserType;
use App\Models\MpesaPaymentSetting;
use App\Models\MpesaQrcodePayment;
use App\Models\MpesaStkCallback;
use App\Models\QrcodePayment;
use App\Models\User;
use App\Services\Payments\QrTokenService;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A passenger pays the bus they are standing in, from whichever of our apps is
 * on their phone. MetroTrans is a 2Safiri SACCO; a passenger with the Komiut app
 * scanning its sticker was told "Invalid or tampered QR code" (2026-11-26).
 *
 * The bus here belongs to brand `other`; the passenger calls from the default
 * test brand's app. The payment must reach the bus and be recorded under the
 * BUS's brand, where its SACCO looks for it.
 */
final class ScanAnyBrandsBusTest extends QueueTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['brands.other' => [
            'name' => 'Other',
            'connection' => config('database.default'),
            'hosts' => [],
            'app_key' => 'other-app-key',
            'features' => [],
        ]]);
        $this->app->forgetInstance(BrandRegistry::class);
    }

    /** A bus of the other brand, with its own Daraja till. */
    private function otherBrandsBus(): array
    {
        $world = $this->makeWorld();
        $world['sacco']->forceFill(['brand' => 'other'])->save();
        $world['vehicle']->forceFill(['brand' => 'other', 'till_number' => '1123849', 'plate' => 'KDW 319J'])->save();
        MpesaPaymentSetting::withoutGlobalScopes()->create([
            'sacco_id' => $world['sacco']->id,
            'consumer_key' => 'ck', 'consumer_secret' => 'cs', 'pass_key' => 'pk',
            'business_short_code' => '7071220', 'payment_mode' => 'CustomerBuyGoodsOnline',
            'is_live' => true, 'status' => true,
        ]);

        return $world;
    }

    private function passenger(): User
    {
        $u = $this->makeUser();
        $u->forceFill(['type' => UserType::Passenger])->save();

        return $u->fresh();
    }

    #[Test]
    public function a_sticker_names_its_bus_whichever_app_scans_it(): void
    {
        $world = $this->otherBrandsBus();
        Sanctum::actingAs($this->passenger());
        $signed = app(QrTokenService::class)->generate(['vehicle_id' => $world['vehicle']->id]);

        foreach ([$signed, '1123849', 'KDW319J', 'kdw 319j', 'https://pay.example/q?till=1123849'] as $sticker) {
            $this->postJson('/api/v1/auth/qrcode/resolve', ['token' => $sticker])
                ->assertOk()
                ->assertJsonPath('vehicle.id', $world['vehicle']->id)
                ->assertJsonPath('vehicle.sacco.id', $world['sacco']->id);
        }

        // Typing the till printed in the bus, too.
        $this->postJson('/api/v1/auth/qrcode/vehicle', ['till_number' => '1123849'])->assertOk();

        // Still nothing for a sticker that names no bus.
        $this->postJson('/api/v1/auth/qrcode/resolve', ['token' => '9999999'])
            ->assertStatus(422)->assertJsonPath('error', 'Invalid or tampered QR code');
    }

    #[Test]
    public function paying_another_brands_bus_by_mpesa_is_recorded_and_settled_under_the_buses_brand(): void
    {
        Http::fake([
            'api.safaricom.co.ke/oauth/v1/generate*' => Http::response(['access_token' => 'tok', 'expires_in' => '3599']),
            'api.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::response([
                'MerchantRequestID' => 'm-1', 'CheckoutRequestID' => 'ws_CO_X_1', 'ResponseCode' => '0',
                'ResponseDescription' => 'Success', 'CustomerMessage' => 'Success',
            ]),
        ]);
        $world = $this->otherBrandsBus();
        Sanctum::actingAs($this->passenger());
        $signed = app(QrTokenService::class)->generate(['vehicle_id' => $world['vehicle']->id]);

        $this->postJson('/api/v1/auth/qrcode/stk/push', ['qr_token' => $signed, 'amount' => 70, 'phone' => '0798881260'])
            ->assertOk()->assertJsonPath('CheckoutRequestID', 'ws_CO_X_1');

        // The bus's till, and Safaricom told to call back on the BUS's brand.
        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), '/processrequest')
            && $r['Amount'] === 70 && $r['PartyB'] === 1123849
            && str_contains($r['CallBackURL'], '/api/other/stk/push/response/'));

        // A QR payment's brand is its bus's (brandVia): the bus's SACCO sees it.
        $payment = QrcodePayment::withoutGlobalScopes()->where('vehicle_id', $world['vehicle']->id)->sole();

        // The passenger's own app follows it.
        $this->getJson('/api/v1/auth/mpesa/stk/status/ws_CO_X_1')->assertOk()->assertJsonPath('status', 'processing');

        // Safaricom's callback, on the bus's brand, settles it.
        $nonce = MpesaStkCallback::where('qrcode_payment_id', $payment->id)->value('callback_nonce');
        $body = json_encode(['Body' => ['stkCallback' => [
            'MerchantRequestID' => 'm-1', 'CheckoutRequestID' => 'ws_CO_X_1', 'ResultCode' => 0, 'ResultDesc' => 'ok',
            'CallbackMetadata' => ['Item' => [
                ['Name' => 'Amount', 'Value' => 70], ['Name' => 'MpesaReceiptNumber', 'Value' => 'UKQ4XBRAND'],
                ['Name' => 'TransactionDate', 'Value' => 20261126120000], ['Name' => 'PhoneNumber', 'Value' => 254798881260],
            ]],
        ]]], JSON_THROW_ON_ERROR);
        $this->call('POST', '/api/other/stk/push/response/'.$nonce, [], [], [], [], $body)->assertOk();

        $this->assertTrue((bool) $payment->fresh()->status);
        $this->assertSame('UKQ4XBRAND', MpesaQrcodePayment::where('qrcode_payment_id', $payment->id)->value('transid'));
        $this->getJson('/api/v1/auth/mpesa/stk/status/ws_CO_X_1')->assertOk()->assertJsonPath('status', 'completed');
    }
}
