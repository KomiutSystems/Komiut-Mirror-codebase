<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Enums\UserType;
use App\Events\FarePaidWithPoints;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use App\Models\MpesaPaymentSetting;
use App\Models\QrcodePayment;
use App\Models\User;
use App\Services\Payments\QrTokenService;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Scanning the sticker on a matatu, step by step, the way it should go:
 *
 *   1. one scan identifies one bus, and the screen can name it and its SACCO;
 *   2. the same screen already knows what this passenger can do here -- their
 *      points with THIS SACCO, in shillings -- before they type a fare;
 *   4. one tap pays, by M-Pesa or by points, and each has a receipt;
 *   5. the crew is told, ESPECIALLY for points, which leave nothing at the door;
 *   7. an old sticker for a bus the SACCO switched off is refused, in words.
 *
 * (3 is arithmetic the app does from `point_value`; 6, earning, has its own
 * tests in RewardsFollowAppPaymentsTest.)
 */
final class ScanningTheBusTest extends QueueTestCase
{
    private function scene(): array
    {
        $world = $this->makeWorld();
        $world['vehicle']->forceFill(['till_number' => '5339502'])->save();
        LoyaltyProgram::withoutGlobalScopes()->create([
            'sacco_id' => $world['sacco']->id, 'is_active' => true,
            'divisor' => 100, 'redemption_threshold' => 50, 'point_value' => 3,
        ]);
        MpesaPaymentSetting::create([
            'sacco_id' => $world['sacco']->id,
            'consumer_key' => 'ck', 'consumer_secret' => 'cs', 'pass_key' => 'pk',
            'business_short_code' => '7071220', 'payment_mode' => 'CustomerBuyGoodsOnline',
            'is_live' => true, 'status' => true,
        ]);

        return $world;
    }

    private function passenger(array $world, float $points): User
    {
        $u = $this->makeUser();
        $u->forceFill(['type' => UserType::Passenger, 'firstname' => 'Tom'])->save();
        LoyaltyAccount::withoutGlobalScopes()->create(['user_id' => $u->id, 'sacco_id' => $world['sacco']->id, 'balance' => $points]);

        return $u;
    }

    private function sticker(array $world): string
    {
        return app(QrTokenService::class)->generate([
            'vehicle_id' => $world['vehicle']->id, 'sacco_id' => $world['sacco']->id, 'plate' => $world['vehicle']->plate,
        ]);
    }

    #[Test]
    public function one_scan_names_the_bus_and_says_what_the_passengers_points_are_worth_here(): void
    {
        $world = $this->scene();
        $tom = $this->passenger($world, points: 50);

        Sanctum::actingAs($tom);
        $r = $this->postJson('/api/v1/auth/qrcode/resolve', ['token' => $this->sticker($world)])->assertOk();

        // Step 1: the bus, its SACCO by name.
        $r->assertJsonPath('vehicle.id', $world['vehicle']->id)
            ->assertJsonPath('vehicle.plate', $world['vehicle']->plate)
            ->assertJsonPath('vehicle.till_number', '5339502')
            ->assertJsonPath('vehicle.sacco.name', $world['sacco']->name);

        // Step 2: what the passenger can do here, before typing anything. This
        // path used to return no card at all; the app needed a second call.
        $r->assertJsonPath('loyalty.sacco_id', $world['sacco']->id)
            ->assertJsonPath('loyalty.balance', 50)
            ->assertJsonPath('loyalty.point_value', 3)
            ->assertJsonPath('loyalty.balance_value', 150)
            ->assertJsonPath('loyalty.eligible_to_redeem', true);

        // Typing the till instead of scanning answers the same way.
        $this->postJson('/api/v1/auth/qrcode/vehicle', ['till_number' => '5339502'])
            ->assertOk()
            ->assertJsonPath('vehicle.sacco.name', $world['sacco']->name)
            ->assertJsonPath('loyalty.balance_value', 150);
    }

    #[Test]
    public function paying_with_points_gives_a_receipt_and_tells_the_crew(): void
    {
        Event::fake([FarePaidWithPoints::class]);
        $world = $this->scene();
        $tom = $this->passenger($world, points: 50);

        Sanctum::actingAs($tom);
        $r = $this->postJson('/api/v1/auth/qrcode/redeem_points', ['vehicle_id' => $world['vehicle']->id, 'amount' => 70])
            ->assertOk()
            ->assertJsonPath('points_spent', 23.33)
            ->assertJsonPath('fare', 70)
            ->assertJsonPath('balance', 26.67);

        // Step 4: the receipt is a real row the conductor can be shown.
        $this->assertTrue((bool) QrcodePayment::withoutGlobalScopes()->find($r->json('payment_id'))->status);

        // Step 5: and the crew is told, on the bus's own channel, in the same
        // shape a till payment arrives in -- amount 0, method points.
        Event::assertDispatched(FarePaidWithPoints::class, function (FarePaidWithPoints $e) use ($world, $r) {
            $payload = $e->broadcastWith();

            return $e->broadcastOn()[0]->name === 'private-vehicle.'.$world['vehicle']->id
                && $e->broadcastAs() === 'payment.recorded'
                && $payload['id'] === $r->json('payment_id')
                && $payload['amount'] === 0.0
                && $payload['method'] === 'points'
                && $payload['fare'] === 70.0
                && $payload['points_spent'] === 23.33
                && $payload['payer'] === 'Tom';
        });
    }

    #[Test]
    public function paying_by_mpesa_reaches_safaricom_with_the_fare_the_passenger_typed(): void
    {
        Http::fake([
            'api.safaricom.co.ke/oauth/v1/generate*' => Http::response(['access_token' => 'tok', 'expires_in' => '3599']),
            'api.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::response([
                'MerchantRequestID' => 'm-1', 'CheckoutRequestID' => 'ws_CO_QR_1', 'ResponseCode' => '0',
                'ResponseDescription' => 'Success', 'CustomerMessage' => 'Success',
            ]),
        ]);
        $world = $this->scene();
        $tom = $this->passenger($world, points: 0);

        Sanctum::actingAs($tom);
        $this->postJson('/api/v1/auth/qrcode/stk/push', ['vehicle_id' => $world['vehicle']->id, 'amount' => 70, 'phone' => '0798881260'])
            ->assertOk()
            ->assertJsonPath('CheckoutRequestID', 'ws_CO_QR_1');

        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), '/processrequest')
            && $r['Amount'] === 70 && $r['PartyB'] === 5339502 && $r['PhoneNumber'] === 254798881260);

        // The passenger can poll their own push.
        $this->getJson('/api/v1/auth/mpesa/stk/status/ws_CO_QR_1')->assertOk()->assertJsonPath('status', 'processing');
    }

    #[Test]
    public function an_old_sticker_for_a_switched_off_bus_is_refused_everywhere(): void
    {
        $world = $this->scene();
        $tom = $this->passenger($world, points: 50);
        $token = $this->sticker($world);
        $world['vehicle']->forceFill(['status' => false])->save();

        Sanctum::actingAs($tom);
        $refusal = 'This QR code is no longer in use. Ask the conductor how to pay.';

        $this->postJson('/api/v1/auth/qrcode/resolve', ['token' => $token])->assertStatus(410)->assertJsonPath('error', $refusal);
        $this->postJson('/api/v1/auth/qrcode/vehicle', ['till_number' => '5339502'])->assertStatus(410)->assertJsonPath('error', $refusal);
        // And an app paying from a vehicle id it resolved before the switch.
        $this->postJson('/api/v1/auth/qrcode/redeem_points', ['vehicle_id' => $world['vehicle']->id, 'amount' => 70])->assertStatus(410);
        Http::fake();
        $this->postJson('/api/v1/auth/qrcode/stk/push', ['vehicle_id' => $world['vehicle']->id, 'amount' => 70, 'phone' => '0798881260'])->assertStatus(410);
        Http::assertNothingSent();

        $this->assertEqualsWithDelta(50, (float) LoyaltyAccount::withoutGlobalScopes()->where('user_id', $tom->id)->value('balance'), 0.001, 'nothing spent');
    }

    #[Test]
    public function a_conductor_who_started_a_push_for_a_walk_in_can_poll_it(): void
    {
        // The push accepts Edit Passengers on a booking the conductor created;
        // the poll only accepted the passenger, so the conductor's own follow-up
        // answered not_found with the passenger standing at the door.
        $world = $this->scene();
        $conductor = $this->makeUser(['Edit Passengers'], $world['sacco']);
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'],
            $this->makeQueueStatus('Pending '.$this->nextSequence(), 'Pending'), $world['owner']);
        $booking = $this->makeBooking($queue, $this->passenger($world, 0), $world['from'], $world['to']);
        $booking->forceFill(['created_by' => $conductor->id, 'amount' => 150])->save();
        \App\Models\MpesaStkCallback::create(['booking_id' => $booking->id, 'callback_nonce' => str_repeat('d', 64), 'checkout_request_id' => 'ws_CO_WALKIN', 'callback' => '{}']);

        Sanctum::actingAs($conductor);
        $this->getJson('/api/v1/auth/mpesa/stk/status/ws_CO_WALKIN')->assertOk()->assertJsonPath('status', 'processing');
    }
}
