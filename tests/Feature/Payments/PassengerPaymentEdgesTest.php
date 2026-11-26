<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Console\Commands\ReconcileMpesaPayments;
use App\Enums\NotificationType;
use App\Enums\PaymentMethod;
use App\Enums\UserType;
use App\Enums\WalletEntryKind;
use App\Events\PaymentUpdated;
use App\Http\Controllers\APIs\MpesaPaymentsController;
use App\Models\MpesaQrcodePayment;
use App\Models\MpesaStkCallback;
use App\Models\User;
use App\Notifications\PlatformNotification;
use App\Services\Ncba\NcbaStkSettlement;
use App\Services\Payments\PassengerPayments\PaymentStatus;
use App\Services\Wallet\WalletOpsService;
use App\Services\Wallet\WalletService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Payments tab at its edges: who has payments at all, stand-in receipts,
 * Safaricom answering late or not knowing, two settlers racing on one
 * payment, and money given back to a wallet.
 */
final class PassengerPaymentEdgesTest extends PassengerPaymentsTestCase
{
    private const LIST = '/api/auth/passenger/payments';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Exceptions::fake();
    }

    protected function tearDown(): void
    {
        Exceptions::assertNothingReported();

        parent::tearDown();
    }

    /** @return array<int, array{id: string, kind: string, status: string, updated_at: ?string}> */
    private function payloads(): array
    {
        return Event::dispatched(PaymentUpdated::class)->map(fn (array $args) => $args[0]->broadcastWith())->values()->all();
    }

    private function farePushes(User $user): Collection
    {
        return Notification::sent($user, PlatformNotification::class)
            ->filter(fn (PlatformNotification $n) => $n->type === NotificationType::Payment)
            ->values();
    }

    private function crewMember(): User
    {
        $user = $this->makeUser();
        $user->forceFill(['type' => UserType::Driver])->save();

        return $user->fresh();
    }

    private function fakeDarajaQuery(array $answer): void
    {
        Http::fake([
            '*oauth/v1/generate*' => Http::response(['access_token' => 'tok']),
            '*stkpushquery*' => Http::response($answer),
        ]);
    }

    // ------------------------------------------------------------------
    // Who has payments
    // ------------------------------------------------------------------

    #[Test]
    public function a_conductors_walk_in_bookings_are_not_their_payments_and_are_not_announced_to_them(): void
    {
        $conductor = $this->crewMember();

        // book_a_ride/booking/add stamps whoever keyed it as user_id.
        Event::fake([PaymentUpdated::class]);
        $walkIn = $this->booking($conductor, ['name' => 'A stranger', 'created_by' => $conductor->id]);
        $walkIn->forceFill(['paid' => true, 'payment_method' => PaymentMethod::Mpesa])->save();
        $this->bookingReceipt($walkIn, 'UJSTRANGER');

        $this->assertSame([], $this->payloads(), "a stranger's fare is never announced on the conductor's channel");

        Sanctum::actingAs($conductor);
        $this->getJson(self::LIST)->assertOk()->assertExactJson(['payments' => [], 'next_cursor' => null]);
        $this->getJson(self::LIST.'/booking:'.$walkIn->id)->assertNotFound();
    }

    #[Test]
    public function a_number_plate_session_gets_an_empty_list_not_a_403(): void
    {
        $me = $this->passenger();
        $fare = $this->qrFare($me, 100, true);
        $this->qrReceipt($fare, 'UJPLATE001');

        $token = $me->createToken('driver-KDN458N', ['crew'])->plainTextToken;
        $plate = ['Authorization' => 'Bearer '.$token];

        $this->getJson(self::LIST, $plate)->assertOk()->assertExactJson(['payments' => [], 'next_cursor' => null]);
        $this->getJson(self::LIST.'/qr_fare:'.$fare->id, $plate)->assertNotFound();

        // The same account signed in with its password sees its own.
        $this->app['auth']->forgetGuards();
        $password = ['Authorization' => 'Bearer '.$me->createToken('Test-AuthToken', ['*'])->plainTextToken];
        $this->getJson(self::LIST, $password)->assertOk()->assertJsonPath('payments.0.id', 'qr_fare:'.$fare->id);
    }

    // ------------------------------------------------------------------
    // Stand-in receipts
    // ------------------------------------------------------------------

    #[Test]
    public function an_ncba_settlement_without_a_receipt_shows_no_code_in_the_row_or_the_push(): void
    {
        config(['services.ncba_stk.username' => 'komiut-stk', 'services.ncba_stk.password' => 'secret-key']);
        Http::fake([
            '*/auth/token' => Http::response(['access_token' => 'jwt-1', 'token_type' => 'Bearer', 'expires_in' => 18000, 'status' => 200]),
            '*/stk-push/query' => Http::response(['status' => 'SUCCESS', 'description' => 'Success']),
        ]);
        $me = $this->passenger();
        $fare = $this->qrFare($me, 100);
        $push = $this->push(['qrcode_payment_id' => $fare->id], 'TX-NCBA-77', ['provider' => MpesaStkCallback::PROVIDER_NCBA, 'phone' => '254700111222']);

        // NCBA says paid before its C2B confirmation has reached `mpesas`.
        $this->assertSame(NcbaStkSettlement::SETTLED, app(NcbaStkSettlement::class)->check($push));
        $this->assertSame('NCBA-TX-NCBA-77', MpesaQrcodePayment::where('qrcode_payment_id', $fare->id)->value('transid'));

        Sanctum::actingAs($me);
        $this->getJson(self::LIST.'/qr_fare:'.$fare->id)->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('mpesa_receipt', null);
        $this->assertSame('Paid KES 100 to '.$this->world['vehicle']->plate, $this->farePushes($me)->sole()->message);
    }

    // ------------------------------------------------------------------
    // Safaricom not knowing, and answering late
    // ------------------------------------------------------------------

    #[Test]
    public function still_under_processing_after_the_cut_off_leaves_the_push_open_for_a_late_paid_callback(): void
    {
        $this->darajaKeys();
        $this->fakeDarajaQuery(['ResponseCode' => '0', 'ResultCode' => '4999', 'ResultDesc' => 'The transaction is still under processing']);
        $me = $this->passenger();

        $fare = $this->qrFare($me, 100);
        $farePush = $this->push(['qrcode_payment_id' => $fare->id], 'ws_CO_4999_QR');
        $this->age('mpesa_stk_callbacks', $farePush->id, now()->subMinutes(11));

        $booking = $this->booking($me);
        $bookingPush = $this->push(['booking_id' => $booking->id], 'ws_CO_4999_BK');
        $this->age('mpesa_stk_callbacks', $bookingPush->id, now()->subMinutes(11));

        Event::fake([PaymentUpdated::class]);
        $this->artisan('payments:reconcile')->assertSuccessful();

        foreach ([$farePush, $bookingPush] as $push) {
            $this->assertNull($push->fresh()->processed_at, 'Safaricom did not know: the nonce stays open');
            $this->assertNotNull($push->fresh()->unconfirmed_at);
        }
        Sanctum::actingAs($me);
        $this->getJson(self::LIST.'/qr_fare:'.$fare->id)->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('status_message', PaymentStatus::MSG_UNCONFIRMED);

        // Then the money's callbacks arrive.
        $this->postCallback($farePush->fresh(), $this->callbackBody('ws_CO_4999_QR', 0, 100, 'UJLATE4999'))->assertOk();
        $this->postCallback($bookingPush->fresh(), $this->callbackBody('ws_CO_4999_BK', 0, (float) $booking->amount, 'UJLATEBK01'))->assertOk();

        $this->assertTrue((bool) $fare->fresh()->status);
        $this->assertTrue((bool) $booking->fresh()->paid);
        $this->getJson(self::LIST.'/qr_fare:'.$fare->id)->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('mpesa_receipt', 'UJLATE4999');
        $this->getJson(self::LIST.'/booking:'.$booking->id)->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('mpesa_receipt', 'UJLATEBK01');

        $byId = [];
        foreach ($this->payloads() as $p) {
            $byId[$p['id']][] = $p['status'];
        }
        $this->assertSame(['failed', 'paid'], $byId['qr_fare:'.$fare->id]);
        $this->assertSame(['failed', 'paid'], $byId['booking:'.$booking->id]);
    }

    #[Test]
    public function refused_pushes_cannot_fill_the_sweeps_batch(): void
    {
        $this->darajaKeys();
        Http::fake([
            '*oauth/v1/generate*' => Http::response(['access_token' => 'tok']),
            '*stkpushquery*' => Http::response([], 500),
        ]);
        $me = $this->passenger();

        // A day's worth of refusals (bad numbers, an outage), older than the
        // silent push behind them and so first in the sweep's id order.
        $refusedFare = $this->qrFare($me, 100);
        $at = now()->subHours(2);
        DB::table('mpesa_stk_callbacks')->insert(array_map(fn () => [
            'qrcode_payment_id' => $refusedFare->id,
            'checkout_request_id' => null,
            'callback' => json_encode(['errorCode' => '400.002.02', 'errorMessage' => 'Bad Request - Invalid PhoneNumber']),
            'created_at' => $at, 'updated_at' => $at,
        ], range(1, ReconcileMpesaPayments::SWEEP_BATCH)));

        $fare = $this->qrFare($me, 100);
        $silent = $this->push(['qrcode_payment_id' => $fare->id], 'ws_CO_BEHIND_1');
        $this->age('mpesa_stk_callbacks', $silent->id, now()->subMinutes(11));

        Event::fake([PaymentUpdated::class]);
        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertNotNull($silent->fresh()->unconfirmed_at, 'the silent push is given up on, refusals or not');
        $this->assertSame(0, MpesaStkCallback::where('qrcode_payment_id', $refusedFare->id)->whereNotNull('unconfirmed_at')->count());
        $this->assertSame([['qr_fare:'.$fare->id, 'failed']], array_map(fn ($p) => [$p['id'], $p['status']], $this->payloads()));
    }

    // ------------------------------------------------------------------
    // Two settlers, one payment
    // ------------------------------------------------------------------

    #[Test]
    public function a_callback_landing_during_the_reconcile_query_is_not_settled_twice(): void
    {
        $this->darajaKeys();
        $me = $this->passenger();
        $fare = $this->qrFare($me, 100);
        $push = $this->push(['qrcode_payment_id' => $fare->id], 'ws_CO_RACE_1');
        $this->age('mpesa_stk_callbacks', $push->id, now()->subMinutes(1));

        Http::fake([
            '*oauth/v1/generate*' => Http::response(['access_token' => 'tok']),
            // While the query is out, Safaricom's real callback lands.
            '*stkpushquery*' => function () use ($push) {
                $this->postCallback($push->fresh(), $this->callbackBody('ws_CO_RACE_1', 0, 100, 'UJRACE0001'))->assertOk();

                return Http::response(['ResponseCode' => '0', 'ResultCode' => '0', 'ResultDesc' => 'The service request is processed successfully.']);
            },
        ]);

        Event::fake([PaymentUpdated::class]);
        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame(['UJRACE0001'], MpesaQrcodePayment::where('qrcode_payment_id', $fare->id)->pluck('transid')->all(), 'one payment, one receipt');
        $this->assertSame(['paid'], array_column($this->payloads(), 'status'));
        $this->assertCount(1, $this->farePushes($me));
    }

    #[Test]
    public function a_second_settlement_of_one_qr_push_writes_nothing(): void
    {
        $me = $this->passenger();
        $fare = $this->qrFare($me, 100);
        $push = $this->push(['qrcode_payment_id' => $fare->id], 'ws_CO_TWICE_1');
        $stale = $push->fresh();
        $paid = fn (string $transid) => ['amount' => 100.0, 'transid' => $transid, 'transdate' => now()->format('Y-m-d H:i:s'), 'phone' => '254700111222'];

        app(MpesaPaymentsController::class)->applyStkResult($push, null, 0, 'ok', $paid('UJTWICE001'), []);
        // A settler holding a copy read before the first one wrote.
        app(MpesaPaymentsController::class)->applyStkResult($stale, null, 0, 'ok', $paid('ws_CO_TWICE_1'), []);

        $this->assertSame(['UJTWICE001'], MpesaQrcodePayment::where('qrcode_payment_id', $fare->id)->pluck('transid')->all());
    }

    #[Test]
    public function a_stale_failed_reading_is_never_announced_after_paid(): void
    {
        $me = $this->passenger();
        $fare = $this->qrFare($me, 100);
        $push = $this->push(['qrcode_payment_id' => $fare->id], 'ws_CO_LATE_RACE');
        $this->age('mpesa_stk_callbacks', $push->id, now()->subMinutes(11));

        Event::fake([PaymentUpdated::class]);

        // The sweep gives up on the push; the moment its announcer first reads
        // the fare, Safaricom's late "paid" is applied on the other host.
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed, $push): void {
            if (! $armed || ! str_contains($query->sql, 'from "qrcode_payments"')) {
                return;
            }
            $armed = false;
            app(MpesaPaymentsController::class)->applyStkResult(MpesaStkCallback::find($push->id), null, 0, 'ok', [
                'amount' => 100.0, 'transid' => 'UJLATERACE', 'transdate' => now()->format('Y-m-d H:i:s'), 'phone' => '254700111222',
            ], []);
        });

        $push->fresh()->forceFill(['unconfirmed_at' => now()])->save();

        $this->assertFalse($armed, 'the race was run');
        $statuses = array_column($this->payloads(), 'status');
        $this->assertSame('paid', end($statuses), 'the last word is the truth');
        $this->assertNotContains('failed', array_slice($statuses, (int) array_search('paid', $statuses, true)), 'nothing stale after paid');
        $this->assertSame(['Fare paid'], $this->farePushes($me)->pluck('title')->all(), 'no false "Payment failed"');
    }

    // ------------------------------------------------------------------
    // Method and times
    // ------------------------------------------------------------------

    #[Test]
    public function an_unpaid_booking_with_a_prompt_reads_mpesa_whatever_was_chosen_at_reservation(): void
    {
        $me = $this->passenger();
        $booking = $this->booking($me, ['payment_method' => PaymentMethod::LoyaltyPoints]);
        $push = $this->push(['booking_id' => $booking->id], 'ws_CO_METHOD_1');

        Sanctum::actingAs($me);
        $this->getJson(self::LIST.'/booking:'.$booking->id)->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('method', 'mpesa')
            ->assertJsonPath('points_spent', null);

        $this->postCallback($push, $this->callbackBody('ws_CO_METHOD_1', 1032))->assertOk();

        $this->getJson(self::LIST.'/booking:'.$booking->id)->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('method', 'mpesa')
            ->assertJsonPath('status_message', PaymentStatus::MSG_CANCELLED_ON_PHONE);

        // With no prompt behind it, the reservation's choice is all there is.
        $held = $this->booking($me, ['payment_method' => PaymentMethod::LoyaltyPoints]);
        $this->getJson(self::LIST.'/booking:'.$held->id)->assertOk()->assertJsonPath('method', 'points');
    }

    #[Test]
    public function a_wallet_payment_keeps_its_paid_time_and_a_support_refund_reads_refunded_and_is_announced(): void
    {
        $me = $this->passenger();
        $staff = $this->makeUser();
        $wallet = app(WalletService::class);
        $wallet->credit($me->id, 100_000, WalletEntryKind::TopUp, 'test:topup:'.$me->id, ['title' => 'Top up']);

        // A wallet-paid booking, paid ten minutes ago, and a wallet tap fare.
        Carbon::setTestNow(now()->subMinutes(10));
        $booking = $this->booking($me, ['paid' => true, 'payment_method' => PaymentMethod::Wallet]);
        $bookingFare = $wallet->debit($me->id, (int) round((float) $booking->amount * 100), WalletEntryKind::Fare, 'fare:booking:'.$booking->id, ['title' => 'Fare', 'booking_id' => $booking->id]);
        $tap = $this->qrFare($me, 0, true, ['fare' => 80, 'payment_method' => PaymentMethod::Wallet->value]);
        $tapFare = $wallet->debit($me->id, 8_000, WalletEntryKind::Fare, 'fare:tap:'.$tap->id, ['title' => 'Fare', 'meta' => ['qrcode_payment_id' => $tap->id]]);
        $paidAt = now()->copy();
        Carbon::setTestNow();

        // Boarding saves the booking later; the paid time must not follow it.
        $booking->forceFill(['boarded' => true])->save();

        Sanctum::actingAs($me);
        $this->getJson(self::LIST.'/booking:'.$booking->id)->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('settled_at', $paidAt->copy()->setTimezone('Africa/Nairobi')->toIso8601String());

        Event::fake([PaymentUpdated::class]);
        app(WalletOpsService::class)->refundFare((int) $bookingFare->id, 'Charged twice', $staff->id);
        app(WalletOpsService::class)->refundFare((int) $tapFare->id, 'Bus never came', $staff->id);

        $this->assertEqualsCanonicalizing(
            [['booking:'.$booking->id, 'refunded'], ['qr_fare:'.$tap->id, 'refunded']],
            array_map(fn ($p) => [$p['id'], $p['status']], $this->payloads()),
        );

        foreach (['booking:'.$booking->id, 'qr_fare:'.$tap->id] as $id) {
            $this->getJson(self::LIST.'/'.$id)->assertOk()
                ->assertJsonPath('status', 'refunded')
                ->assertJsonPath('method', 'wallet')
                ->assertJsonPath('status_message', PaymentStatus::MSG_REFUNDED_TO_WALLET)
                ->assertJsonPath('settled_at', $paidAt->copy()->setTimezone('Africa/Nairobi')->toIso8601String());
        }

        // The filter (SQL) agrees with the rows (PHP).
        $refunded = array_column($this->getJson(self::LIST.'?status=refunded')->assertOk()->json('payments'), 'id');
        sort($refunded);
        $want = ['booking:'.$booking->id, 'qr_fare:'.$tap->id];
        sort($want);
        $this->assertSame($want, $refunded);
        $this->getJson(self::LIST.'?status=paid')->assertOk()->assertJsonCount(0, 'payments');
    }
}
