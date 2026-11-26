<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Enums\BookingCancellationReason;
use App\Enums\NotificationType;
use App\Enums\PaymentMethod;
use App\Events\PaymentUpdated;
use App\Models\LoyaltyAccount;
use App\Models\MpesaStkCallback;
use App\Models\QrcodePayment;
use App\Models\User;
use App\Notifications\PlatformNotification;
use App\Services\Booking\BookingCancellation;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Payments\PassengerPayments\PaymentStatus;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * payment.updated: once per change, after commit, on the payer's own channel
 * -- and silent when Safaricom says the same thing twice.
 */
final class PaymentUpdatedTest extends PassengerPaymentsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        // Every announce failure is report()ed and swallowed by design; faking
        // the handler turns a swallowed failure into a failed test.
        Exceptions::fake();
    }

    protected function tearDown(): void
    {
        if (! $this->expectsReportedFailure) {
            Exceptions::assertNothingReported();
        }

        parent::tearDown();
    }

    private bool $expectsReportedFailure = false;

    /** @return array<int, PaymentUpdated> */
    private function announced(): array
    {
        return Event::dispatched(PaymentUpdated::class)->map(fn (array $args) => $args[0])->values()->all();
    }

    /** @return array<int, array{id: string, kind: string, status: string, updated_at: ?string}> */
    private function payloads(): array
    {
        return array_map(fn (PaymentUpdated $e) => $e->broadcastWith(), $this->announced());
    }

    private function assertOnChannelOf(User $user, PaymentUpdated $event): void
    {
        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-App.Models.User.'.$user->id, $channels[0]->name);
        $this->assertSame('payment.updated', $event->broadcastAs());
        $this->assertSame(['id', 'kind', 'status', 'updated_at'], array_keys($event->broadcastWith()));
    }

    private function farePushes(User $user): Collection
    {
        return Notification::sent($user, PlatformNotification::class)
            ->filter(fn (PlatformNotification $n) => $n->type === NotificationType::Payment)
            ->values();
    }

    #[Test]
    public function a_qr_stk_prompt_is_announced_once_as_pending_on_the_payers_channel(): void
    {
        Event::fake([PaymentUpdated::class]);
        $this->darajaKeys();
        Http::fake([
            '*oauth/v1/generate*' => Http::response(['access_token' => 'tok', 'expires_in' => '3599']),
            '*stkpush/v1/processrequest' => Http::response([
                'MerchantRequestID' => 'm-1', 'CheckoutRequestID' => 'ws_CO_PROMPT_1',
                'ResponseCode' => '0', 'ResponseDescription' => 'Success. Request accepted for processing',
            ]),
        ]);
        $me = $this->passenger();
        Sanctum::actingAs($me);

        $this->postJson('/api/auth/qrcode/stk/push', [
            'vehicle_id' => $this->world['vehicle']->id, 'amount' => 100, 'phone' => '0700111222',
        ])->assertOk();

        $fare = QrcodePayment::withoutGlobalScopes()->where('user_id', $me->id)->sole();
        $this->assertSame([['id' => 'qr_fare:'.$fare->id, 'kind' => 'qr_fare', 'status' => 'pending', 'updated_at' => $this->payloads()[0]['updated_at']]], $this->payloads());
        $this->assertOnChannelOf($me, $this->announced()[0]);
        $this->assertCount(0, $this->farePushes($me), 'nothing to push while the PIN prompt is up');

        // The pointer resolves: the app re-reads it and sees the prompt.
        $this->getJson('/api/auth/passenger/payments/qr_fare:'.$fare->id)->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('checkout_request_id', 'ws_CO_PROMPT_1');
    }

    #[Test]
    public function a_paid_callback_is_announced_and_pushed_once_and_a_resend_adds_nothing(): void
    {
        $me = $this->passenger();
        $fare = $this->qrFare($me, 100);
        $push = $this->push(['qrcode_payment_id' => $fare->id], 'ws_CO_PAID_1');

        Event::fake([PaymentUpdated::class]);
        $this->postCallback($push, $this->callbackBody('ws_CO_PAID_1', 0, 100, 'UJ6BJ9BXGZ'))->assertOk();

        $this->assertSame(['paid'], array_column($this->payloads(), 'status'));
        $this->assertOnChannelOf($me, $this->announced()[0]);

        $pushes = $this->farePushes($me);
        $this->assertCount(1, $pushes);
        $this->assertSame('Fare paid', $pushes[0]->title);
        $this->assertSame('Paid KES 100 to '.$this->world['vehicle']->plate.' · UJ6BJ9BXGZ', $pushes[0]->message);
        $this->assertSame('qr_fare:'.$fare->id, $pushes[0]->referenceId);
        $this->assertSame('Umelipa KES 100 kwa '.$this->world['vehicle']->plate.' · UJ6BJ9BXGZ', $pushes[0]->fcmFor('sw')['message']);

        // Safaricom resends the same callback.
        $this->postCallback($push, $this->callbackBody('ws_CO_PAID_1', 0, 100, 'UJ6BJ9BXGZ'))->assertOk();

        $this->assertCount(1, $this->announced(), 'a resent callback changes nothing and says nothing');
        $this->assertCount(1, $this->farePushes($me));
    }

    #[Test]
    public function a_failed_callback_is_announced_and_pushed_once_with_the_reason(): void
    {
        $me = $this->passenger();
        $fare = $this->qrFare($me, 100);
        $push = $this->push(['qrcode_payment_id' => $fare->id], 'ws_CO_FAIL_1');

        Event::fake([PaymentUpdated::class]);
        $this->postCallback($push, $this->callbackBody('ws_CO_FAIL_1', 1032))->assertOk();
        $this->postCallback($push, $this->callbackBody('ws_CO_FAIL_1', 1032))->assertOk();

        $this->assertSame(['failed'], array_column($this->payloads(), 'status'));
        $pushes = $this->farePushes($me);
        $this->assertCount(1, $pushes);
        $this->assertSame('Payment failed', $pushes[0]->title);
        $this->assertSame('KES 100 to '.$this->world['vehicle']->plate.' was not paid. '.PaymentStatus::MSG_CANCELLED_ON_PHONE, $pushes[0]->message);

        Sanctum::actingAs($me);
        $this->getJson('/api/auth/passenger/payments/qr_fare:'.$fare->id)->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('status_message', PaymentStatus::MSG_CANCELLED_ON_PHONE);
    }

    #[Test]
    public function a_booking_paid_by_callback_is_announced_once(): void
    {
        $me = $this->passenger();
        $booking = $this->booking($me);
        $push = $this->push(['booking_id' => $booking->id], 'ws_CO_BKG_1');

        Event::fake([PaymentUpdated::class]);
        $this->postCallback($push, $this->callbackBody('ws_CO_BKG_1', 0, 200, 'BKG1RECEIPT'))->assertOk();
        $this->postCallback($push, $this->callbackBody('ws_CO_BKG_1', 0, 200, 'BKG1RECEIPT'))->assertOk();

        $this->assertSame([['id' => 'booking:'.$booking->id, 'status' => 'paid']], array_map(
            fn ($p) => ['id' => $p['id'], 'status' => $p['status']],
            $this->payloads(),
        ));
        $this->assertOnChannelOf($me, $this->announced()[0]);
        $this->assertCount(0, $this->farePushes($me), 'a booking already gets "Booking confirmed"');
    }

    #[Test]
    public function an_app_cancel_is_announced(): void
    {
        $me = $this->passenger();
        $booking = $this->booking($me);
        $this->push(['booking_id' => $booking->id], 'ws_CO_CANCEL_1');

        Event::fake([PaymentUpdated::class]);
        Sanctum::actingAs($me);
        $this->postJson('/api/auth/mpesa/stk/cancel/ws_CO_CANCEL_1')->assertOk();
        $this->postJson('/api/auth/mpesa/stk/cancel/ws_CO_CANCEL_1')->assertOk();

        $this->assertSame(['cancelled'], array_column($this->payloads(), 'status'));
        $this->assertSame('booking:'.$booking->id, $this->payloads()[0]['id']);
    }

    #[Test]
    public function a_refund_is_announced(): void
    {
        $this->loyaltyProgram();
        $me = $this->passenger();
        $booking = $this->booking($me, ['paid' => true, 'payment_method' => PaymentMethod::Mpesa]);
        $this->bookingReceipt($booking, 'REFUND0001');

        Event::fake([PaymentUpdated::class]);
        $this->assertTrue(app(BookingCancellation::class)->notBoarded($booking->fresh(), BookingCancellationReason::NoShow));

        $this->assertSame(['refunded'], array_column($this->payloads(), 'status'));
        $this->assertOnChannelOf($me, $this->announced()[0]);
    }

    #[Test]
    public function a_points_fare_is_announced_once_already_paid(): void
    {
        $this->loyaltyProgram();
        $me = $this->passenger();
        LoyaltyAccount::withoutGlobalScopes()->create(['user_id' => $me->id, 'sacco_id' => $this->world['sacco']->id, 'balance' => 500]);

        Event::fake([PaymentUpdated::class]);
        $result = app(LoyaltyService::class)->redeemForVehicle($me, $this->world['vehicle'], 70);
        $this->assertTrue($result['ok']);

        $this->assertSame([['id' => 'qr_fare:'.$result['payment']->id, 'status' => 'paid']], array_map(
            fn ($p) => ['id' => $p['id'], 'status' => $p['status']],
            $this->payloads(),
        ));
        $this->assertCount(0, $this->farePushes($me), 'decided at the tap, shown at the tap');

        Sanctum::actingAs($me);
        $this->getJson('/api/auth/passenger/payments/qr_fare:'.$result['payment']->id)->assertOk()
            ->assertJsonPath('method', 'points')
            ->assertJsonPath('amount', 70)
            ->assertJsonPath('points_spent', 70);
    }

    #[Test]
    public function nothing_is_announced_until_the_change_commits_and_nothing_if_it_rolls_back(): void
    {
        $me = $this->passenger();
        $fare = $this->qrFare($me, 100);

        Event::fake([PaymentUpdated::class]);

        DB::transaction(function () use ($fare) {
            $this->push(['qrcode_payment_id' => $fare->id], 'ws_CO_TX_1');
            Event::assertNotDispatched(PaymentUpdated::class);
        });
        $this->assertSame(['pending'], array_column($this->payloads(), 'status'));

        $other = $this->qrFare($me, 100);
        try {
            DB::transaction(function () use ($other) {
                $this->push(['qrcode_payment_id' => $other->id], 'ws_CO_TX_2');
                throw new RuntimeException('settlement failed');
            });
        } catch (RuntimeException) {
        }
        $this->assertCount(1, $this->announced(), 'a rolled-back prompt was never there');
    }

    #[Test]
    public function the_sweep_gives_up_on_a_silent_push_and_a_late_callback_still_pays_it(): void
    {
        $this->darajaKeys();
        Http::fake([
            '*oauth/v1/generate*' => Http::response(['access_token' => 'tok']),
            // Safaricom cannot say.
            '*stkpushquery*' => Http::response([], 500),
        ]);
        $me = $this->passenger();
        $fare = $this->qrFare($me, 100);
        $push = $this->push(['qrcode_payment_id' => $fare->id], 'ws_CO_SILENT_1');
        $this->age('mpesa_stk_callbacks', $push->id, now()->subMinutes(11));

        Event::fake([PaymentUpdated::class]);
        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertNotNull($push->fresh()->unconfirmed_at);
        $this->assertNull($push->fresh()->processed_at, 'the nonce stays open for a late callback');
        $this->assertSame(['failed'], array_column($this->payloads(), 'status'));
        $this->assertSame('KES 100 to '.$this->world['vehicle']->plate.' was not paid. '.PaymentStatus::MSG_UNCONFIRMED, $this->farePushes($me)[0]->message);

        Sanctum::actingAs($me);
        $this->getJson('/api/auth/passenger/payments/qr_fare:'.$fare->id)->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('status_message', PaymentStatus::MSG_UNCONFIRMED);

        // The next run finds nothing new to say.
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertCount(1, $this->announced());

        // Then Safaricom's callback arrives after all: the money moved.
        $this->postCallback($push->fresh(), $this->callbackBody('ws_CO_SILENT_1', 0, 100, 'UJLATE0001'))->assertOk();

        $this->assertSame(['failed', 'paid'], array_column($this->payloads(), 'status'));
        $this->assertSame('Paid KES 100 to '.$this->world['vehicle']->plate.' · UJLATE0001', $this->farePushes($me)[1]->message);
        $this->getJson('/api/auth/passenger/payments/qr_fare:'.$fare->id)->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('mpesa_receipt', 'UJLATE0001')
            ->assertJsonPath('status_message', null);
    }

    #[Test]
    public function the_sweep_settles_a_pending_qr_stk_from_safaricoms_answer(): void
    {
        $this->darajaKeys();
        $me = $this->passenger();

        $paidFare = $this->qrFare($me, 100);
        $paidPush = $this->push(['qrcode_payment_id' => $paidFare->id], 'ws_CO_Q_PAID');
        $this->age('mpesa_stk_callbacks', $paidPush->id, now()->subMinutes(3));

        $failedFare = $this->qrFare($me, 100);
        $failedPush = $this->push(['qrcode_payment_id' => $failedFare->id], 'ws_CO_Q_FAIL');
        $this->age('mpesa_stk_callbacks', $failedPush->id, now()->subMinutes(3));

        Http::fake([
            '*oauth/v1/generate*' => Http::response(['access_token' => 'tok']),
            '*stkpushquery*' => fn ($request) => str_contains($request->body(), 'ws_CO_Q_PAID')
                ? Http::response(['ResponseCode' => '0', 'ResultCode' => '0', 'ResultDesc' => 'The service request is processed successfully.'])
                : Http::response(['ResponseCode' => '0', 'ResultCode' => '1', 'ResultDesc' => 'The balance is insufficient for the transaction.']),
        ]);

        Event::fake([PaymentUpdated::class]);
        $this->artisan('payments:reconcile')->assertSuccessful();

        $byId = array_column($this->payloads(), 'status', 'id');
        $this->assertSame(['qr_fare:'.$paidFare->id => 'paid', 'qr_fare:'.$failedFare->id => 'failed'], $byId);
        $this->assertTrue((bool) $paidFare->fresh()->status);
        $this->assertSame(1, (int) $failedPush->fresh()->result_code, 'what Safaricom said is kept');

        Sanctum::actingAs($me);
        $this->getJson('/api/auth/passenger/payments/qr_fare:'.$paidFare->id)->assertOk()
            ->assertJsonPath('status', 'paid')
            // STK Push Query carries no receipt; the checkout id is not one.
            ->assertJsonPath('mpesa_receipt', null);
        $this->getJson('/api/auth/passenger/payments/qr_fare:'.$failedFare->id)->assertOk()
            ->assertJsonPath('status_message', PaymentStatus::MSG_INSUFFICIENT_FUNDS);
    }

    #[Test]
    public function a_broadcast_that_fails_is_reported_and_never_reaches_safaricom(): void
    {
        $this->expectsReportedFailure = true;
        Event::listen(PaymentUpdated::class, fn () => throw new RuntimeException('socket server down'));

        $me = $this->passenger();
        $fare = $this->qrFare($me, 100);
        $push = $this->push(['qrcode_payment_id' => $fare->id], 'ws_CO_DOWN_1');

        $this->postCallback($push, $this->callbackBody('ws_CO_DOWN_1', 0, 100, 'UJDOWN0001'))
            ->assertOk();

        $this->assertTrue((bool) $fare->fresh()->status, 'the payment stands');
        $this->assertNotNull($push->fresh()->processed_at);
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'socket server down');
        // The push is guarded apart from the socket frame.
        $this->assertCount(1, $this->farePushes($me));
    }

    #[Test]
    public function a_new_push_row_on_an_already_settled_payment_says_nothing(): void
    {
        $me = $this->passenger();
        $fare = $this->qrFare($me, 100, true);
        $this->qrReceipt($fare, 'UJSETTLED1');

        Event::fake([PaymentUpdated::class]);
        MpesaStkCallback::query()->create([
            'qrcode_payment_id' => $fare->id,
            'callback_nonce' => bin2hex(random_bytes(32)),
            'callback' => '{}',
        ]);

        $this->assertSame([], $this->announced());
    }
}
