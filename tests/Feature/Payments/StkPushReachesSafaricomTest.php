<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Enums\UserType;
use App\Models\Booking;
use App\Models\MpesaBookingCallback;
use App\Models\MpesaPaymentSetting;
use App\Models\MpesaStkCallback;
use App\Models\PlatformNotification;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A passenger's STK push must actually reach Safaricom.
 *
 * THE BUG, 2026-09-12. The first real booking on Frankfurt (#7) was followed one
 * second later by POST mpesa/stk -> 401, retried -> 401, and the passenger saw
 * "could not start mpesa payment please retry". Daraja was never called. The
 * controller loaded the vehicle's payment settings through the Eloquent
 * relations, and MpesaPaymentSetting is a SACCO-owned table: SaccoScope fails
 * closed for a caller with no SACCO, which is every passenger. So the settings
 * were NULL for every vehicle on the platform, for every passenger, and the push
 * answered "No payments found for this sacco" -- while the identical lookup
 * from an unauthenticated shell found the row and made the credentials look
 * healthy. The one log line that would have said so was Log::info, below the
 * production log level.
 *
 * Nothing caught it because no test ever ran the happy path: the push went to
 * Daraja over raw curl, so it could not be faked, so it was never exercised.
 * These are that test. Daraja is Http::fake()d and every assertion is on what
 * was SENT to it.
 */
final class StkPushReachesSafaricomTest extends QueueTestCase
{
    private const PUSH = '/api/v1/auth/mpesa/stk';

    private const QR_PUSH = '/api/v1/auth/qrcode/stk/push';

    private function passenger(): User
    {
        $u = $this->makeUser([], null);
        $u->forceFill(['type' => UserType::Passenger])->save();
        $this->assertNull($u->sacco_id, 'the trap needs a caller with no SACCO');

        return $u->fresh();
    }

    /** Live STK credentials on the vehicle's SACCO, the usual shape in production. */
    private function saccoSettings(array $world, string $shortCode = '7071220'): MpesaPaymentSetting
    {
        return MpesaPaymentSetting::create([
            'sacco_id' => $world['sacco']->id,
            'consumer_key' => 'ck-'.$shortCode, 'consumer_secret' => 'cs-'.$shortCode, 'pass_key' => 'pk-'.$shortCode,
            'business_short_code' => $shortCode, 'payment_mode' => 'CustomerBuyGoodsOnline',
            'is_live' => true, 'status' => true,
        ]);
    }

    private function unpaidBooking(array $world, User $passenger, float $amount): Booking
    {
        $queue = $this->makeQueue(
            $world['vehicle'], $world['terminus'], $world['route'],
            $this->makeQueueStatus('Pending '.$this->nextSequence(), 'Pending'), $world['owner'],
        );
        $booking = $this->makeBooking($queue, $passenger, $world['from'], $world['to']);
        $booking->forceFill(['amount' => $amount, 'paid' => false])->save();

        return $booking->fresh();
    }

    private function fakeDarajaAccepting(): void
    {
        Http::fake([
            'api.safaricom.co.ke/oauth/v1/generate*' => Http::response(['access_token' => 'tok-live', 'expires_in' => '3599']),
            'api.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::response([
                'MerchantRequestID' => 'm-1', 'CheckoutRequestID' => 'ws_CO_TEST_1',
                'ResponseCode' => '0', 'ResponseDescription' => 'Success. Request accepted for processing',
                'CustomerMessage' => 'Success. Request accepted for processing',
            ]),
        ]);
    }

    #[Test]
    public function a_passenger_paying_their_own_booking_reaches_daraja_with_the_saccos_till(): void
    {
        $this->fakeDarajaAccepting();
        $world = $this->makeWorld();
        $this->saccoSettings($world, '7071220');
        $world['vehicle']->forceFill(['till_number' => '5339502'])->save();
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);

        Sanctum::actingAs($passenger);
        $response = $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id]);

        $response->assertOk()->assertJsonPath('CheckoutRequestID', 'ws_CO_TEST_1');

        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), '/oauth/v1/generate')
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('ck-7071220:cs-7071220')));
        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), '/mpesa/stkpush/v1/processrequest')
            && $r['BusinessShortCode'] === 7071220
            && $r['Amount'] === 150                    // the server's fare, not the client's
            && $r['PartyB'] === 5339502                // BuyGoods pays the bus's till
            && $r['PhoneNumber'] === 254798881260
            && $r['TransactionType'] === 'CustomerBuyGoodsOnline'
            && $r['AccountReference'] === (string) $booking->id);

        $this->assertSame('ws_CO_TEST_1', MpesaStkCallback::where('booking_id', $booking->id)->value('checkout_request_id'),
            'the push is recorded so the app can poll and the reconciler can recover a lost callback');
    }

    #[Test]
    public function the_vehicles_own_settings_win_over_the_saccos(): void
    {
        $this->fakeDarajaAccepting();
        $world = $this->makeWorld();
        $this->saccoSettings($world, '7071220');
        $own = MpesaPaymentSetting::create([
            'sacco_id' => $world['sacco']->id,
            'consumer_key' => 'ck-own', 'consumer_secret' => 'cs-own', 'pass_key' => 'pk-own',
            'business_short_code' => '5339502', 'payment_mode' => 'CustomerPayBillOnline',
            'is_live' => true, 'status' => true,
        ]);
        $world['vehicle']->forceFill(['mpesa_payment_setting_id' => $own->id])->save();
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 80);

        Sanctum::actingAs($passenger);
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertOk();

        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), '/processrequest')
            && $r['BusinessShortCode'] === 5339502
            && $r['PartyB'] === 5339502                // PayBill pays the shortcode itself
            && $r['TransactionType'] === 'CustomerPayBillOnline');
    }

    #[Test]
    public function a_scanned_bus_is_paid_the_same_way(): void
    {
        // The QR path loaded the settings through the same scoped relations.
        $this->fakeDarajaAccepting();
        $world = $this->makeWorld();
        $this->saccoSettings($world, '7071220');
        $world['vehicle']->forceFill(['till_number' => '5339502'])->save();
        $passenger = $this->passenger();

        Sanctum::actingAs($passenger);
        $this->postJson(self::QR_PUSH, ['vehicle_id' => $world['vehicle']->id, 'amount' => 70, 'phone' => '0798881260'])
            ->assertOk()
            ->assertJsonPath('CheckoutRequestID', 'ws_CO_TEST_1');

        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), '/processrequest')
            && $r['Amount'] === 70 && $r['PartyB'] === 5339502);
        $this->assertDatabaseHas('qrcode_payments', ['vehicle_id' => $world['vehicle']->id, 'user_id' => $passenger->id, 'amount' => 70]);
    }

    #[Test]
    public function a_bus_with_no_payment_settings_is_a_422_not_a_sign_out(): void
    {
        // Every failure on this endpoint used to be 401. The app reads 401 as
        // "session ended": the access log shows the handset re-authenticating
        // one second after each refused push. Not being able to pay a bus is a
        // fact about the bus.
        Http::fake();
        $world = $this->makeWorld();
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);

        Sanctum::actingAs($passenger);
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    #[Test]
    public function safaricom_refusing_our_credentials_is_a_503_and_is_logged_where_production_can_see_it(): void
    {
        Http::fake(['api.safaricom.co.ke/oauth/v1/generate*' => Http::response('', 400)]);
        $world = $this->makeWorld();
        $this->saccoSettings($world);
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);

        Log::spy();

        Sanctum::actingAs($passenger);
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])
            ->assertStatus(503);

        Http::assertNotSent(fn (ClientRequest $r) => str_contains($r->url(), '/processrequest'));
        // WARNING, not info: production runs at `warning`, and this is the line
        // that says why no prompt reached the handset.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => $message === 'daraja token request failed' && ($context['status'] ?? null) === 400)
            ->once();
    }

    #[Test]
    public function a_push_daraja_refuses_does_not_come_back_as_a_200(): void
    {
        Http::fake([
            'api.safaricom.co.ke/oauth/v1/generate*' => Http::response(['access_token' => 'tok', 'expires_in' => '3599']),
            'api.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::response([
                'requestId' => 'r-1', 'errorCode' => '400.002.02', 'errorMessage' => 'Bad Request - Invalid PhoneNumber',
            ], 400),
        ]);
        $world = $this->makeWorld();
        $this->saccoSettings($world);
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);

        Sanctum::actingAs($passenger);
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])
            ->assertStatus(502)
            ->assertJsonPath('errorMessage', 'Bad Request - Invalid PhoneNumber');

        $this->assertNull(MpesaStkCallback::where('booking_id', $booking->id)->value('checkout_request_id'),
            'nothing to poll: Daraja accepted no request');
    }

    #[Test]
    public function the_other_passengers_booking_is_still_refused_before_any_till_is_looked_at(): void
    {
        Http::fake();
        $world = $this->makeWorld();
        $this->saccoSettings($world);
        $victim = $this->passenger();
        $booking = $this->unpaidBooking($world, $victim, 150);

        Sanctum::actingAs($this->passenger());
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertStatus(403);

        Http::assertNothingSent();
    }

    #[Test]
    public function a_second_push_while_the_first_prompt_is_still_open_returns_the_first_and_raises_nothing(): void
    {
        // A prompt lives on the handset for up to two minutes. An app that gave
        // up at 30 s and offered "try again" raised a SECOND real prompt while
        // the first could still be paid -- and both could go through. One open
        // prompt per booking: the retry gets the open push's CheckoutRequestID
        // back, marked as a replay, and Safaricom is not asked again.
        $this->fakeDarajaAccepting();
        $world = $this->makeWorld();
        $this->saccoSettings($world);
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);

        Sanctum::actingAs($passenger);
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])
            ->assertOk()->assertJsonPath('CheckoutRequestID', 'ws_CO_TEST_1')->assertJsonMissingPath('replay');
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])
            ->assertOk()->assertJsonPath('CheckoutRequestID', 'ws_CO_TEST_1')->assertJsonPath('replay', true);

        Http::assertSentCount(2); // one token, one push -- the retry sent nothing
        $this->assertSame(1, MpesaStkCallback::where('booking_id', $booking->id)->count());

        // Cancelling in the app does NOT free it: Daraja has no cancel, and the
        // prompt is still on the handset, payable. (Until 2026-10-07 it did,
        // and "try again" raised a second prompt beside the first.)
        $this->postJson('/api/v1/auth/mpesa/stk/cancel/ws_CO_TEST_1')->assertOk();
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])
            ->assertStatus(503)
            ->assertJsonPath('reason', 'prompt_still_open')
            ->assertHeader('Retry-After');
        $this->assertSame(1, MpesaStkCallback::where('booking_id', $booking->id)->count());

        // Safaricom answering it -- the passenger dismissed it on the phone --
        // is what frees the booking.
        $this->safaricomAnswers(MpesaStkCallback::where('booking_id', $booking->id)->value('callback_nonce'), 1032);
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])
            ->assertOk()->assertJsonMissingPath('replay');
        $this->assertSame(2, MpesaStkCallback::where('booking_id', $booking->id)->count());
    }

    /** Safaricom's callback for a push, by its nonce: a ResultCode, and the metadata of a payment when it is 0. */
    private function safaricomAnswers(string $nonce, int $resultCode, string $receipt = 'UJ6TEST001', float $amount = 150): void
    {
        $stk = ['MerchantRequestID' => 'm-1', 'CheckoutRequestID' => 'ws_CO_TEST_1', 'ResultCode' => $resultCode, 'ResultDesc' => 'test'];
        if ($resultCode === 0) {
            $stk['CallbackMetadata'] = ['Item' => [
                ['Name' => 'Amount', 'Value' => $amount],
                ['Name' => 'MpesaReceiptNumber', 'Value' => $receipt],
                ['Name' => 'TransactionDate', 'Value' => 20261007091500],
                ['Name' => 'PhoneNumber', 'Value' => 254798881260],
            ]];
        }
        $body = json_encode(['Body' => ['stkCallback' => $stk]], JSON_THROW_ON_ERROR);
        $this->call('POST', '/api/testing/stk/push/response/'.$nonce, [], [], [], [], $body)->assertOk();
    }

    /** Daraja times out on the first $timeouts pushes, then accepts. */
    private function fakeDarajaTimingOut(int $timeouts = PHP_INT_MAX): void
    {
        $pushes = 0;
        Http::fake([
            'api.safaricom.co.ke/oauth/v1/generate*' => Http::response(['access_token' => 'tok-live', 'expires_in' => '3599']),
            'api.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => function () use (&$pushes, $timeouts) {
                if ($pushes++ < $timeouts) {
                    throw new ConnectionException('cURL error 28: Operation timed out after 20001 milliseconds');
                }

                return Http::response([
                    'MerchantRequestID' => 'm-1', 'CheckoutRequestID' => 'ws_CO_TEST_1',
                    'ResponseCode' => '0', 'ResponseDescription' => 'Success. Request accepted for processing',
                ]);
            },
        ]);
    }

    #[Test]
    public function a_push_safaricom_never_answered_holds_the_booking_and_its_late_payment_still_lands(): void
    {
        // Our call to Daraja timed out, but Safaricom had accepted it: the
        // prompt reaches the phone anyway and the passenger pays. "Try again"
        // used to raise a second prompt for the same seat -- the push record
        // had no CheckoutRequestID, so the one-open-prompt guard skipped it.
        $this->fakeDarajaTimingOut();
        $world = $this->makeWorld();
        $this->saccoSettings($world);
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);

        Sanctum::actingAs($passenger);
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])
            ->assertStatus(503)
            ->assertJsonPath('reason', 'push_unconfirmed');

        // The retry raises nothing: still the one push on record.
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])
            ->assertStatus(503)
            ->assertJsonPath('reason', 'push_unconfirmed');
        $this->assertSame(1, MpesaStkCallback::where('booking_id', $booking->id)->count());

        // The prompt was paid after all: the callback finds it by its nonce.
        $this->safaricomAnswers(MpesaStkCallback::where('booking_id', $booking->id)->value('callback_nonce'), 0);
        $this->assertTrue((bool) $booking->fresh()->paid);

        // And a push after that is refused as already paid, not charged again.
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])
            ->assertStatus(422);
    }

    #[Test]
    public function an_unanswered_push_stops_holding_the_booking_when_the_prompt_has_expired(): void
    {
        $this->fakeDarajaTimingOut(timeouts: 1);
        $world = $this->makeWorld();
        $this->saccoSettings($world);
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);

        Sanctum::actingAs($passenger);
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertStatus(503);

        $this->travel(121)->seconds();
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])
            ->assertOk()->assertJsonPath('CheckoutRequestID', 'ws_CO_TEST_1');
    }

    #[Test]
    public function a_push_safaricom_refused_holds_nothing(): void
    {
        // An error body from Daraja means no prompt went out: the passenger
        // corrects the number and tries again at once.
        Http::fake([
            'api.safaricom.co.ke/oauth/v1/generate*' => Http::response(['access_token' => 'tok-live', 'expires_in' => '3599']),
            'api.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::sequence()
                ->push(['requestId' => 'r-1', 'errorCode' => '400.002.02', 'errorMessage' => 'Bad Request - Invalid PhoneNumber'], 400)
                ->push(['MerchantRequestID' => 'm-1', 'CheckoutRequestID' => 'ws_CO_TEST_1', 'ResponseCode' => '0', 'ResponseDescription' => 'Success']),
        ]);
        $world = $this->makeWorld();
        $this->saccoSettings($world);
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);

        Sanctum::actingAs($passenger);
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertStatus(502);
        $this->postJson(self::PUSH, ['phone' => '0798881261', 'booking_id' => $booking->id])
            ->assertOk()->assertJsonPath('CheckoutRequestID', 'ws_CO_TEST_1');
    }

    #[Test]
    public function a_second_payment_for_a_booking_already_paid_is_kept_and_flagged_for_a_refund(): void
    {
        // Paid with points, then an M-Pesa prompt for the same booking paid
        // too: two fares for one ride. The money is real -- the receipt is
        // kept -- and it goes to the super console to be given back.
        $this->fakeDarajaAccepting();
        $world = $this->makeWorld();
        $this->saccoSettings($world);
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);

        Sanctum::actingAs($passenger);
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertOk();
        $booking->forceFill(['paid' => true, 'payment_method' => 'loyalty_points'])->save();

        $this->safaricomAnswers(MpesaStkCallback::where('booking_id', $booking->id)->value('callback_nonce'), 0, 'UJ6DOUBLE1');

        $this->assertSame('UJ6DOUBLE1', MpesaBookingCallback::where('booking_id', $booking->id)->value('transid'));
        $this->assertSame('loyalty_points', $booking->fresh()->payment_method->value,
            'the ride stays paid the way it was paid first');
        $this->assertSame(1, PlatformNotification::where('event', 'payment.reconciliation.failed')->count());
        $this->assertNotNull(MpesaStkCallback::where('booking_id', $booking->id)->value('processed_at'));
    }

    #[Test]
    public function the_status_poll_says_why_a_push_failed_in_safaricoms_own_terms(): void
    {
        // A failed push used to be recorded as nothing but processed_at, so the
        // poll could only say "failed". Daraja's ResultCode is on every callback
        // and now on the record: the passenger is told they cancelled, not that
        // something went wrong.
        $this->fakeDarajaAccepting();
        $world = $this->makeWorld();
        $this->saccoSettings($world);
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);

        Sanctum::actingAs($passenger);
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertOk();
        $nonce = MpesaStkCallback::where('booking_id', $booking->id)->value('callback_nonce');

        $body = json_encode(['Body' => ['stkCallback' => [
            'MerchantRequestID' => 'm-1', 'CheckoutRequestID' => 'ws_CO_TEST_1',
            'ResultCode' => 1032, 'ResultDesc' => 'Request cancelled by user',
        ]]], JSON_THROW_ON_ERROR);
        $this->call('POST', '/api/testing/stk/push/response/'.$nonce, [], [], [], [], $body)->assertOk();

        $this->getJson('/api/v1/auth/mpesa/stk/status/ws_CO_TEST_1')
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('resultCode', 1)
            ->assertJsonPath('darajaResultCode', 1032)
            ->assertJsonPath('reason', 'cancelled_by_user')
            ->assertJsonPath('message', 'You cancelled the M-Pesa prompt.');

        $this->assertFalse((bool) $booking->fresh()->paid);
        // And the booking is free for a fresh prompt: the failed one is not "open".
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])
            ->assertOk()->assertJsonMissingPath('replay');
    }
}
