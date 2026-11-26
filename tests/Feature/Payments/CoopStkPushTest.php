<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Brands\BrandRegistry;
use App\Enums\UserType;
use App\Jobs\PollCoopStkPush;
use App\Models\Booking;
use App\Models\CoopMpesaStkCallback;
use App\Models\Mpesa;
use App\Models\MpesaBookingCallback;
use App\Models\Transaction;
use App\Models\MpesaPaymentSetting;
use App\Models\MpesaQrcodePayment;
use App\Models\MpesaStkCallback;
use App\Models\QrcodePayment;
use App\Models\User;
use App\Services\Coop\CoopStkAnswer;
use App\Services\Coop\CoopStkSettlement;
use App\Models\PlatformNotification;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Co-operative Bank STK Push: an in-app payment on a bus that collects on a
 * Co-op till, pushed as the bank's production collection describes. The bank
 * is Http::fake()d. Answers marked "live" are what the bank really sent on
 * 2026-11-26; the rest are SHAPES THE CODE MUST SURVIVE.
 */
final class CoopStkPushTest extends QueueTestCase
{
    private const PUSH = '/api/v1/auth/mpesa/stk';

    private const QR_PUSH = '/api/v1/auth/qrcode/stk/push';

    private const BASE = 'https://openapi-sit.co-opbank.co.ke';

    private const CALLBACK = 'https://bankpayments.komiut.com/api/coop/stk/callback';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.coop_stk' => [
            'enabled' => true, 'base_url' => self::BASE,
            'consumer_key' => 'ck-coop', 'consumer_secret' => 'cs-coop', 'callback_url' => self::CALLBACK,
        ]]);
    }

    /** A bus banked with Co-op: its Co-op till is the match code, the bank has filed it under an operator code, and it has no Daraja keys. */
    private function coopWorld(string $till = '1123852', string $account = '01101233137021', string $operatorCode = 'KDW 322J'): array
    {
        $world = $this->makeWorld();
        $world['vehicle']->forceFill(['merchant_short_code' => $till, 'coop_till' => $account, 'plate' => 'KDW 322J', 'coop_operator_code' => $operatorCode])->save();

        return $world;
    }

    private function passenger(): User
    {
        $u = $this->makeUser([], null);
        $u->forceFill(['type' => UserType::Passenger])->save();

        return $u->fresh();
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

    /**
     * @param  array<string, mixed>  $enquiry  what Enquiry/STK answers
     * @param  array<string, mixed>|null  $push  what FT/stk answers
     */
    private function fakeCoop(array $enquiry = ['MessageCode' => '0', 'Status' => 'PENDING'], ?array $push = null, int $pushStatus = 200): void
    {
        Http::fake([
            self::BASE.'/token' => Http::response(['access_token' => 'jwt-1', 'token_type' => 'Bearer', 'expires_in' => 3600]),
            self::BASE.'/FT/stk/1.0.0' => Http::response($push ?? ['MessageCode' => '0', 'MessageDescription' => 'REQUEST ACCEPTED FOR PROCESSING'], $pushStatus),
            self::BASE.'/Enquiry/STK/1.0.0/' => Http::response($enquiry),
        ]);
    }

    /** Run the poll job the push queued, as the worker would. */
    private function runPoll(MpesaStkCallback $push, int $check = 0): void
    {
        (new PollCoopStkPush($push->id, $check))->handle(app(CoopStkSettlement::class));
    }

    private function pushFor(Booking $booking): MpesaStkCallback
    {
        return MpesaStkCallback::where('booking_id', $booking->id)->sole();
    }

    #[Test]
    public function a_booking_on_a_coop_bus_is_pushed_through_the_bank_exactly_as_its_collection_says(): void
    {
        $this->fakeCoop();
        $world = $this->coopWorld();
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);

        Sanctum::actingAs($passenger);
        $response = $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])
            ->assertOk()
            ->assertJsonPath('ResponseCode', '0')
            ->assertJsonPath('provider', 'coop');

        $push = $this->pushFor($booking);
        $reference = (string) $push->callback_nonce;
        $this->assertMatchesRegularExpression('/^K[A-F0-9]{18}$/', $reference);
        // Our reference stands where Daraja's CheckoutRequestID does, so the app polls as it always has.
        $response->assertJsonPath('CheckoutRequestID', $reference);
        $this->assertSame('coop', $push->provider);
        $this->assertSame($reference, $push->checkout_request_id);
        $this->assertSame('254798881260', $push->phone);

        Http::assertSent(fn (ClientRequest $r) => $r->method() === 'POST' && $r->url() === self::BASE.'/token'
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('ck-coop:cs-coop'))
            && $r->isForm() && $r['grant_type'] === 'client_credentials');
        Http::assertSent(fn (ClientRequest $r) => $r->url() === self::BASE.'/FT/stk/1.0.0'
            && $r->hasHeader('Authorization', 'Bearer jwt-1')
            && $r['MessageReference'] === $reference
            && ! isset($r['UserId'])
            && $r['CallBackUrl'] === self::CALLBACK
            // The bus's own code, exactly as the bank filed it: that decides the account.
            && $r['OperatorCode'] === 'KDW 322J'
            // Sent as the bank's collection sends it: slashes unescaped.
            && str_contains($r->body(), '"CallBackUrl":"https://bankpayments.komiut.com/api/coop/stk/callback"')
            && $r['TransactionCurrency'] === 'KES'
            && $r['MobileNumber'] === '254798881260'
            && $r['Amount'] === 150                     // the server's fare
            // The bus's till: what the bank's payment notification is matched by.
            && $r['Narration'] === '1123852'
            && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $r['MessageDateTime']) === 1
            && $r['OtherDetails'] === [['Name' => 'COOP', 'Value' => $reference]]);
        Http::assertNotSent(fn (ClientRequest $r) => str_contains($r->url(), 'safaricom.co.ke'));
        Bus::assertDispatched(PollCoopStkPush::class, fn (PollCoopStkPush $job) => $job->stkId === $push->id && $job->check === 0);
    }

    #[Test]
    public function an_enquiry_carrying_a_receipt_settles_the_booking_like_a_daraja_callback(): void
    {
        $this->fakeCoop(['MessageCode' => '0', 'MessageDescription' => 'Success', 'Status' => 'SUCCESS', 'MpesaReceiptNumber' => 'UK5431AUQI', 'Amount' => '150.00']);
        $world = $this->coopWorld();
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);
        Sanctum::actingAs($passenger);
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertOk();
        $push = $this->pushFor($booking);

        $this->runPoll($push);

        Http::assertSent(fn (ClientRequest $r) => $r->url() === self::BASE.'/Enquiry/STK/1.0.0/'
            && $r['MessageReference'] === $push->callback_nonce && ! isset($r['UserId']));
        $booking->refresh();
        $this->assertTrue((bool) $booking->paid);
        $this->assertSame('mpesa', $booking->payment_method?->value ?? $booking->payment_method);
        $this->assertSame('UK5431AUQI', MpesaBookingCallback::where('booking_id', $booking->id)->value('transid'));
        $this->getJson('/api/auth/mpesa/stk/status/'.$push->callback_nonce)
            ->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('mpesaReceiptNumber', 'UK5431AUQI');
        Bus::assertDispatchedTimes(PollCoopStkPush::class, 1);    // settled: no further check queued
    }

    #[Test]
    public function an_answer_that_only_says_paid_settles_when_the_banks_own_feed_shows_the_money(): void
    {
        $this->fakeCoop(['MessageCode' => '0', 'TransactionStatus' => 'Completed']);
        $world = $this->coopWorld();
        $passenger = $this->passenger();
        Sanctum::actingAs($passenger);
        $booking = $this->unpaidBooking($world, $passenger, 150);
        // The same payer paid the same fare on this bus BEFORE the prompt: not this payment.
        Mpesa::withoutGlobalScopes()->create(['TransID' => 'UK1OLD00AA', 'MSISDN' => '254798881260', 'TransAmount' => 150,
            'TransTime' => now(), 'FirstName' => 'Test', 'LastName' => 'Payer', 'BusinessShortCode' => '1123852', 'BillRefNumber' => '1123852'])
            ->forceFill(['created_at' => now()->subMinutes(10)])->save();
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertOk();
        $push = $this->pushFor($booking);

        // "Completed", no receipt, and no money on the feed yet: says paid, proves nothing.
        $this->runPoll($push);
        $this->assertFalse((bool) $booking->fresh()->paid);
        $this->assertNull($push->fresh()->processed_at);
        Bus::assertDispatched(PollCoopStkPush::class, fn (PollCoopStkPush $job) => $job->stkId === $push->id && $job->check === 1);

        // The payment, as Co-op's payment feed files it against the bus's till.
        Mpesa::withoutGlobalScopes()->create(['TransID' => 'UK2M40C21M', 'MSISDN' => '254798881260', 'TransAmount' => 150,
            'TransTime' => now(), 'FirstName' => 'Test', 'LastName' => 'Payer', 'BusinessShortCode' => '1123852', 'BillRefNumber' => '1123852']);
        $this->runPoll($push, 1);

        $this->assertTrue((bool) $booking->fresh()->paid);
        $this->assertSame('UK2M40C21M', MpesaBookingCallback::where('booking_id', $booking->id)->value('transid'));
    }

    #[Test]
    public function request_received_is_not_paid_so_an_answer_that_only_says_success_about_itself_keeps_asking(): void
    {
        // How these APIs acknowledge a call. It says nothing about the payment.
        $this->fakeCoop(['MessageReference' => 'x', 'MessageCode' => '0', 'MessageDescription' => 'Success']);
        $world = $this->coopWorld();
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);
        Sanctum::actingAs($passenger);
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertOk();
        $push = $this->pushFor($booking);

        $this->runPoll($push, 0);

        $this->assertFalse((bool) $booking->fresh()->paid);
        $this->assertNull($push->fresh()->processed_at);
        Bus::assertDispatched(PollCoopStkPush::class, fn (PollCoopStkPush $job) => $job->stkId === $push->id && $job->check === 1);
        $this->getJson('/api/auth/mpesa/stk/status/'.$push->callback_nonce)->assertJsonPath('status', 'processing');

        // The last scheduled check stops; payments:reconcile takes over from there.
        $count = count(Bus::dispatched(PollCoopStkPush::class));
        $this->runPoll($push, count(PollCoopStkPush::NEXT_CHECKS));
        $this->assertCount($count, Bus::dispatched(PollCoopStkPush::class));
    }

    #[Test]
    public function a_failed_push_is_closed_with_the_banks_reason_and_the_booking_stays_unpaid(): void
    {
        $this->fakeCoop(['MessageCode' => '0', 'Status' => 'FAILED', 'MessageDescription' => 'Request cancelled by user']);
        $world = $this->coopWorld();
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);
        Sanctum::actingAs($passenger);
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertOk();
        $push = $this->pushFor($booking);

        $this->runPoll($push);

        $this->assertFalse((bool) $booking->fresh()->paid);
        $this->getJson('/api/auth/mpesa/stk/status/'.$push->callback_nonce)
            ->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('message', 'Request cancelled by user');
        // Closed, so a fresh push for the same booking may go at once.
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertOk()->assertJsonMissing(['replay' => true]);
    }

    #[Test]
    public function a_paid_answer_for_a_different_sum_is_not_this_fare_and_settles_nothing(): void
    {
        $this->fakeCoop(['Status' => 'SUCCESS', 'MpesaReceiptNumber' => 'UK5431AUQI', 'Amount' => '15.00']);
        $world = $this->coopWorld();
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);
        Sanctum::actingAs($passenger);
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertOk();

        $this->runPoll($this->pushFor($booking));

        $this->assertFalse((bool) $booking->fresh()->paid);
        $this->assertNull($this->pushFor($booking)->processed_at);
    }

    #[Test]
    public function a_refused_push_is_a_502_with_the_banks_reason_and_holds_nothing(): void
    {
        Http::fake([
            self::BASE.'/token' => Http::response(['access_token' => 'jwt-1', 'expires_in' => 3600]),
            self::BASE.'/FT/stk/1.0.0' => Http::sequence()
                ->push(['MessageCode' => '-2', 'MessageDescription' => 'INVALID MOBILE NUMBER'], 400)
                ->push(['MessageCode' => '0', 'MessageDescription' => 'REQUEST ACCEPTED FOR PROCESSING']),
        ]);
        $world = $this->coopWorld();
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);
        Sanctum::actingAs($passenger);

        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])
            ->assertStatus(502)->assertJsonPath('ResponseDescription', 'INVALID MOBILE NUMBER')->assertJsonMissingPath('CheckoutRequestID');

        Bus::assertNotDispatched(PollCoopStkPush::class);
        $push = $this->pushFor($booking);
        $this->assertNull($push->checkout_request_id);
        // Nothing to ask about, and the passenger may correct the number and go.
        $this->assertSame(CoopStkSettlement::DONE, app(CoopStkSettlement::class)->check($push));
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertOk();
    }

    #[Test]
    public function a_push_the_bank_never_answered_holds_the_booking_and_is_still_asked_about_and_settled(): void
    {
        Http::fake([
            self::BASE.'/token' => Http::response(['access_token' => 'jwt-1', 'expires_in' => 3600]),
            self::BASE.'/FT/stk/1.0.0' => Http::response('<html>Gateway Timeout</html>', 504),
            self::BASE.'/Enquiry/STK/1.0.0/' => Http::response(['Status' => 'SUCCESS', 'MpesaReceiptNumber' => 'UK5431AUQI']),
        ]);
        $world = $this->coopWorld();
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);
        Sanctum::actingAs($passenger);

        // No answer is not no prompt: the passenger is told to look at the phone, not to try again.
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])
            ->assertStatus(503)->assertJsonPath('reason', 'push_unconfirmed');
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])
            ->assertStatus(503)->assertJsonPath('reason', 'push_unconfirmed');
        $push = $this->pushFor($booking);    // sole(): the second tap raised nothing
        Bus::assertDispatched(PollCoopStkPush::class, fn (PollCoopStkPush $job) => $job->stkId === $push->id);

        // The bank had taken it after all. Our reference finds it.
        $this->runPoll($push);

        $this->assertTrue((bool) $booking->fresh()->paid);
        $this->assertSame($push->callback_nonce, $push->fresh()->checkout_request_id);
    }

    #[Test]
    public function the_banks_callback_is_kept_and_only_brings_the_enquiry_forward(): void
    {
        $this->fakeCoop(['MessageCode' => '0', 'Status' => 'PENDING']);
        config(['brands.testing.hosts' => ['localhost', 'bankpayments.komiut.com']]);
        $this->app->forgetInstance(BrandRegistry::class);
        $world = $this->coopWorld();
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);
        Sanctum::actingAs($passenger);
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertOk();
        $push = $this->pushFor($booking);

        // A callback announcing success -- which anyone who learnt the reference could send.
        $this->postJson(self::CALLBACK, ['MessageReference' => $push->callback_nonce, 'MessageCode' => '0',
            'Status' => 'SUCCESS', 'MpesaReceiptNumber' => 'FORGED0001', 'Amount' => 150])
            ->assertOk()->assertExactJson(['MessageCode' => '200', 'Message' => 'Successfully received data']);

        // Kept, and it queued one check -- but it marked nothing paid.
        $this->assertSame(1, CoopMpesaStkCallback::count());
        Bus::assertDispatched(PollCoopStkPush::class, fn (PollCoopStkPush $job) => $job->stkId === $push->id && $job->check === PollCoopStkPush::ONE_OFF);
        $this->assertFalse((bool) $booking->fresh()->paid);

        // The check it queued asks the bank, which says the payment is not made; a one-off schedules no other.
        $count = count(Bus::dispatched(PollCoopStkPush::class));
        $this->runPoll($push, PollCoopStkPush::ONE_OFF);
        $this->assertFalse((bool) $booking->fresh()->paid);
        $this->assertSame(0, MpesaBookingCallback::where('transid', 'FORGED0001')->count());
        $this->assertCount($count, Bus::dispatched(PollCoopStkPush::class));

        // Naming the same push again and again buys no more enquiries.
        $this->postJson(self::CALLBACK, ['MessageReference' => $push->callback_nonce, 'Status' => 'SUCCESS'])->assertOk();
        $this->assertCount($count, Bus::dispatched(PollCoopStkPush::class));

        // A callback naming nothing of ours is acknowledged, kept nowhere, and does nothing.
        $this->postJson(self::CALLBACK, ['MessageReference' => 'K000000000000000000', 'Status' => 'SUCCESS'])->assertOk();
        $this->postJson(self::CALLBACK, ['MessageReference' => ['not', 'a', 'string'], 'x' => "bad\0ref"])->assertOk();
        $this->postJson(self::CALLBACK, [])->assertOk();
        $this->assertCount($count, Bus::dispatched(PollCoopStkPush::class));
        $this->assertSame(2, CoopMpesaStkCallback::count());
    }

    #[Test]
    public function a_qr_payment_on_a_coop_bus_is_pushed_and_settled_with_its_rewards_path(): void
    {
        $this->fakeCoop(['Status' => 'SUCCESS', 'MpesaReceiptNumber' => 'UK5431AUQI']);
        $world = $this->coopWorld('1170919', '01101233137071', 'KDY688D');
        $passenger = $this->passenger();
        Sanctum::actingAs($passenger);

        $this->postJson(self::QR_PUSH, ['vehicle_id' => $world['vehicle']->id, 'amount' => 70, 'phone' => '0798881260'])
            ->assertOk()->assertJsonPath('provider', 'coop');
        Http::assertSent(fn (ClientRequest $r) => $r->url() === self::BASE.'/FT/stk/1.0.0'
            && $r['Amount'] === 70 && $r['OperatorCode'] === 'KDY688D');

        $payment = QrcodePayment::where('vehicle_id', $world['vehicle']->id)->sole();
        $this->runPoll(MpesaStkCallback::where('qrcode_payment_id', $payment->id)->sole());

        $this->assertTrue((bool) $payment->fresh()->status);
        $this->assertSame('UK5431AUQI', MpesaQrcodePayment::where('qrcode_payment_id', $payment->id)->value('transid'));
    }

    #[Test]
    public function reconcile_settles_a_coop_push_the_poller_left_open_and_never_asks_daraja_about_it(): void
    {
        $this->fakeCoop(['Status' => 'SUCCESS', 'MpesaReceiptNumber' => 'UK5431AUQI']);
        $world = $this->coopWorld();
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);
        Sanctum::actingAs($passenger);
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertOk();
        $push = $this->pushFor($booking);
        $push->forceFill(['created_at' => now()->subMinutes(5)])->save();

        Artisan::call('payments:reconcile');

        // Handed to the queue as one check, so a hanging bank cannot stall the command.
        Bus::assertDispatched(PollCoopStkPush::class, fn (PollCoopStkPush $job) => $job->stkId === $push->id && $job->check === PollCoopStkPush::ONE_OFF);
        $this->runPoll($push, PollCoopStkPush::ONE_OFF);

        $this->assertTrue((bool) $booking->fresh()->paid);
        $this->assertNotNull($push->fresh()->processed_at);
        Http::assertNotSent(fn (ClientRequest $r) => str_contains($r->url(), 'safaricom.co.ke'));
    }

    #[Test]
    public function a_2xx_answer_with_a_code_we_do_not_know_is_unconfirmed_not_refused(): void
    {
        // Neither Co-op's "0" nor one of its negative refusals: the prompt may be on the handset.
        Http::fake([
            self::BASE.'/token' => Http::response(['access_token' => 'jwt-1', 'expires_in' => 3600]),
            self::BASE.'/FT/stk/1.0.0' => Http::response(['MessageCode' => 'S001', 'MessageDescription' => 'Queued']),
            self::BASE.'/Enquiry/STK/1.0.0/' => Http::response(['Status' => 'SUCCESS', 'MpesaReceiptNumber' => 'UK5431AUQI']),
        ]);
        $world = $this->coopWorld();
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);
        Sanctum::actingAs($passenger);

        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])
            ->assertStatus(503)->assertJsonPath('reason', 'push_unconfirmed');
        $push = $this->pushFor($booking);
        $this->assertFalse(CoopStkSettlement::wasRefused($push));
        Bus::assertDispatched(PollCoopStkPush::class, fn (PollCoopStkPush $job) => $job->stkId === $push->id);

        $this->runPoll($push);
        $this->assertTrue((bool) $booking->fresh()->paid);
    }

    #[Test]
    public function with_no_token_nothing_is_sent_and_nothing_is_held(): void
    {
        // What the bank's address allowlist answers: an HTML page, 403.
        Http::fake([self::BASE.'/token' => Http::response('<HTML><TITLE>Access Denied</TITLE></HTML>', 403)]);
        $world = $this->coopWorld();
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);
        Sanctum::actingAs($passenger);

        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertStatus(503);

        $this->assertSame(0, MpesaStkCallback::where('booking_id', $booking->id)->count());
        Bus::assertNotDispatched(PollCoopStkPush::class);
        Http::assertNotSent(fn (ClientRequest $r) => str_contains($r->url(), '/FT/stk/'));
    }

    #[Test]
    public function a_token_the_bank_no_longer_honours_is_replaced_and_the_push_sent_again(): void
    {
        Http::fake([
            self::BASE.'/token' => Http::sequence()
                ->push(['access_token' => 'jwt-old', 'expires_in' => 3600])
                ->push(['access_token' => 'jwt-new', 'expires_in' => 3600]),
            self::BASE.'/FT/stk/1.0.0' => Http::sequence()
                ->push(['fault' => ['code' => 900901, 'message' => 'Invalid Credentials']], 401)
                ->push(['MessageCode' => '0', 'MessageDescription' => 'REQUEST ACCEPTED FOR PROCESSING']),
        ]);
        $world = $this->coopWorld();
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);
        Sanctum::actingAs($passenger);

        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertOk();

        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), '/FT/stk/') && $r->hasHeader('Authorization', 'Bearer jwt-new'));
    }

    #[Test]
    public function a_check_queued_under_another_brand_still_settles_the_bus_under_its_own(): void
    {
        // The bank posts its callback to a komiut.com host; the Co-op fleet is on another brand.
        $this->fakeCoop(['Status' => 'SUCCESS', 'MpesaReceiptNumber' => 'UK5431AUQI']);
        $world = $this->coopWorld();
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);
        Sanctum::actingAs($passenger);
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertOk();
        $push = $this->pushFor($booking);

        Context::add('brand', 'some-other-brand');
        $this->runPoll($push, PollCoopStkPush::ONE_OFF);

        $this->assertTrue((bool) Booking::withoutGlobalScopes()->find($booking->id)->paid);
        $this->assertNotNull($push->fresh()->processed_at);
    }

    #[Test]
    public function two_checks_of_one_push_cannot_both_apply_the_answer(): void
    {
        $this->fakeCoop(['Status' => 'SUCCESS', 'MpesaReceiptNumber' => 'UK5431AUQI']);
        $world = $this->coopWorld();
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);
        Sanctum::actingAs($passenger);
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertOk();
        $stale = $this->pushFor($booking);    // a copy read before the other check settled it

        $this->assertSame(CoopStkSettlement::SETTLED, app(CoopStkSettlement::class)->check($this->pushFor($booking)));
        // Held while another check is settling it: asked again later, nothing applied.
        $lock = Cache::lock('coop_stk:settle:'.$stale->id, 30);
        $this->assertTrue($lock->get());
        $stale->processed_at = null;
        $this->assertSame(CoopStkSettlement::PENDING, app(CoopStkSettlement::class)->check($stale));
        $lock->release();
        // And once free, the stale copy finds the push already done.
        $this->assertSame(CoopStkSettlement::DONE, app(CoopStkSettlement::class)->check($stale));

        $this->assertSame(1, MpesaBookingCallback::where('booking_id', $booking->id)->count());
    }

    #[Test]
    public function the_token_is_fetched_once_and_reused(): void
    {
        $this->fakeCoop();
        $world = $this->coopWorld();
        $passenger = $this->passenger();
        Sanctum::actingAs($passenger);
        foreach ([150, 120] as $fare) {
            $booking = $this->unpaidBooking($world, $passenger, $fare);
            $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertOk();
        }

        Http::assertSentCount(3);    // one token, two pushes
    }

    #[Test]
    public function credentials_alone_switch_nothing_on(): void
    {
        config(['services.coop_stk.enabled' => false]);
        Http::fake();
        $world = $this->coopWorld();
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);
        Sanctum::actingAs($passenger);

        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])
            ->assertStatus(422)->assertJsonPath('error', 'This vehicle is not set up for M-Pesa payments yet.');
        Http::assertNothingSent();
    }

    #[Test]
    public function a_bus_the_bank_has_not_given_an_operator_code_is_not_a_coop_bus(): void
    {
        Http::fake();
        $world = $this->coopWorld();
        $world['vehicle']->forceFill(['coop_operator_code' => null])->save();
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);
        Sanctum::actingAs($passenger);

        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertStatus(422);
        Http::assertNothingSent();
    }

    #[Test]
    public function a_bus_that_can_pay_through_safaricom_still_does(): void
    {
        Http::fake([
            'api.safaricom.co.ke/oauth/v1/generate*' => Http::response(['access_token' => 'tok-live', 'expires_in' => '3599']),
            'api.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::response([
                'MerchantRequestID' => 'm-1', 'CheckoutRequestID' => 'ws_CO_TEST_1', 'ResponseCode' => '0',
                'ResponseDescription' => 'Success. Request accepted for processing', 'CustomerMessage' => 'Success. Request accepted for processing',
            ]),
        ]);
        $world = $this->coopWorld();
        MpesaPaymentSetting::create([
            'sacco_id' => $world['sacco']->id, 'consumer_key' => 'ck', 'consumer_secret' => 'cs', 'pass_key' => 'pk',
            'business_short_code' => '7071220', 'payment_mode' => 'CustomerBuyGoodsOnline', 'is_live' => true, 'status' => true,
        ]);
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);
        Sanctum::actingAs($passenger);

        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])
            ->assertOk()->assertJsonPath('CheckoutRequestID', 'ws_CO_TEST_1');

        $this->assertNull($this->pushFor($booking)->provider);
        Http::assertNotSent(fn (ClientRequest $r) => str_contains($r->url(), 'co-opbank.co.ke'));
    }

    /** @param  array<string, mixed>  $body */
    #[Test]
    #[DataProvider('answers')]
    public function an_answer_is_read_by_what_it_proves(array $body, string $verdict, ?string $receipt = null): void
    {
        $answer = new CoopStkAnswer(200, $body);

        $this->assertSame($verdict, $answer->verdict());
        $this->assertSame($receipt, $answer->receipt());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string, 2?: string}> */
    public static function answers(): array
    {
        return [
            'a receipt is proof, wherever it sits' => [['MessageCode' => '0', 'Destinations' => [['ResponseCode' => '0', 'MpesaReceiptNumber' => 'uk5431auqi']]], CoopStkAnswer::SUCCESS, 'UK5431AUQI'],
            'a receipt spelt as a name and value pair' => [['OtherDetails' => [['Name' => 'MpesaReceiptNumber', 'Value' => 'UK5431AUQI']]], CoopStkAnswer::SUCCESS, 'UK5431AUQI'],
            'a status that says paid, with no receipt, is a claim' => [['messageCode' => '0', 'transactionStatus' => 'Successful'], CoopStkAnswer::CLAIMED],
            'so is the bare status an api gives its own call' => [['MessageCode' => '0', 'MessageDescription' => 'Success', 'Status' => 'SUCCESS'], CoopStkAnswer::CLAIMED],
            'received is not paid' => [['MessageCode' => '0', 'MessageDescription' => 'Success'], CoopStkAnswer::PENDING],
            'not paid is not paid' => [['MessageCode' => '0', 'TransactionStatus' => 'Not Paid'], CoopStkAnswer::PENDING],
            'not successful is not successful' => [['Status' => 'Not Successful'], CoopStkAnswer::PENDING],
            'pending' => [['Status' => 'PENDING'], CoopStkAnswer::PENDING],
            'pending, whatever sits under a receipt key' => [['Status' => 'PENDING', 'ReceiptNumber' => 'Processing'], CoopStkAnswer::PENDING],
            'a receipt against a failed status contradicts itself' => [['Status' => 'FAILED', 'MpesaReceiptNumber' => 'UK5431AUQI'], CoopStkAnswer::PENDING, 'UK5431AUQI'],
            'ten zeros are not a receipt' => [['Status' => 'PROCESSING', 'MpesaReceiptNumber' => '0000000000'], CoopStkAnswer::PENDING],
            'nor is a ten digit number' => [['ReceiptNo' => '1234567890'], CoopStkAnswer::PENDING],
            'not filed yet is not a refusal' => [['MessageCode' => '-1', 'MessageDescription' => 'MESSAGE REFERENCE NOT FOUND'], CoopStkAnswer::PENDING],
            'the enquiry failing is not the payment failing' => [['MessageCode' => '-1', 'MessageDescription' => 'Backend service timed out, please try again'], CoopStkAnswer::PENDING],
            'nor is a general refusal of the call' => [['MessageCode' => '-9', 'MessageDescription' => 'ENQUIRY FAILED'], CoopStkAnswer::PENDING],
            'a failed status' => [['Status' => 'FAILED', 'ResultDesc' => 'Something went wrong'], CoopStkAnswer::FAILED],
            'cancelled on the handset' => [['MessageDescription' => 'Request cancelled by user'], CoopStkAnswer::FAILED],
            'the handset could not be reached' => [['MessageCode' => '-3', 'MessageDescription' => 'DS timeout user cannot be reached'], CoopStkAnswer::FAILED],
            'insufficient funds' => [['ResponseDescription' => 'The balance is insufficient for the transaction'], CoopStkAnswer::FAILED],
            'nothing we recognise' => [['foo' => 'bar'], CoopStkAnswer::PENDING],
            // Live, 2026-11-26.
            'live: paid, the receipt inside the narration' => [self::LIVE_PAID, CoopStkAnswer::SUCCESS, 'UKQ4340ZXN'],
            'live: processing' => [['MessageReference' => '2SAF-261126083334', 'MessageCode' => 'S_001', 'MessageDescription' => 'PROCESSING',
                'MessageDetails' => 'Transaction is being processed', 'TransactionMetadata' => ['Items' => [['Name' => 'Narration', 'Value' => 'Coop Test~2026-11-26']]],
                'OtherInfo' => [['Name' => '', 'Value' => '']]], CoopStkAnswer::PENDING],
            'live: not filed yet' => [['MessageReference' => '2SAF-261126082153', 'MessageCode' => '-13', 'MessageDescription' => 'Message Reference does not exist'], CoopStkAnswer::PENDING],
            'live: the bank\'s backend timing out' => [['MessageReference' => '{"MessageID":null}', 'MessageCode' => 'Service', 'MessageDescription' => 'Backend timeout'], CoopStkAnswer::PENDING],
            'a receipt in the narration counts only under the paid code' => [['MessageCode' => 'S_001', 'MessageDescription' => 'PROCESSING',
                'TransactionMetadata' => ['Items' => [['Name' => 'Narration', 'Value' => '1123852~UKQ4340ZXN~2026-11-26']]]], CoopStkAnswer::PENDING],
            'a narration with no receipt in it' => [['MessageCode' => '0', 'MessageDescription' => 'FULL SUCCESS', 'TransactionMetadata' => ['Items' => [['Name' => 'Narration', 'Value' => 'Fare KDW322J~2026-11-26']]]], CoopStkAnswer::PENDING],
            'cancelled, in Co-op\'s words' => [['MessageCode' => '-1', 'MessageDescription' => 'Request Cancelled'], CoopStkAnswer::FAILED],
        ];
    }

    /** What Enquiry/STK answered for a paid KES 1 prompt, 2026-11-26. */
    private const LIVE_PAID = [
        'MessageReference' => '2SAF-261126082455', 'MessageDateTime' => '2026-11-26T11:11:19', 'MessageCode' => '0',
        'MessageDescription' => 'FULL SUCCESS', 'MessageDetails' => 'Transaction processed successfully',
        'TransactionMetadata' => ['Items' => [
            ['Name' => 'Amount', 'Value' => '1.0'], ['Name' => 'PhoneNumber', 'Value' => '254705708643'],
            ['Name' => 'Narration', 'Value' => 'Coop Test~UKQ4340ZXN~2026-11-26'],
        ]],
        'OtherInfo' => [['Name' => '', 'Value' => '']],
    ];

    /** The live paid answer, for this test's fare, payer and narration. */
    private static function livePaid(float $amount, string $phone): array
    {
        $body = self::LIVE_PAID;
        $body['TransactionMetadata']['Items'] = [
            ['Name' => 'Amount', 'Value' => number_format($amount, 1, '.', '')], ['Name' => 'PhoneNumber', 'Value' => $phone],
            ['Name' => 'Narration', 'Value' => '1123852~UKQ4340ZXN~2026-11-26'],
        ];

        return $body;
    }

    #[Test]
    public function the_banks_real_paid_answer_settles_the_booking_with_the_receipt_from_the_narration(): void
    {
        $this->fakeCoop(self::livePaid(150, '254798881260'));
        $world = $this->coopWorld();
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);
        Sanctum::actingAs($passenger);
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertOk();

        $this->runPoll($this->pushFor($booking));

        $this->assertTrue((bool) $booking->fresh()->paid);
        $this->assertSame('UKQ4340ZXN', MpesaBookingCallback::where('booking_id', $booking->id)->value('transid'));
    }

    #[Test]
    public function a_paid_answer_from_another_number_is_not_this_passengers_and_settles_nothing(): void
    {
        $this->fakeCoop(self::livePaid(150, '254700000001'));
        $world = $this->coopWorld();
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);
        Sanctum::actingAs($passenger);
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertOk();
        $push = $this->pushFor($booking);

        $this->assertSame(CoopStkSettlement::UNKNOWN, app(CoopStkSettlement::class)->check($push));
        $this->assertFalse((bool) $booking->fresh()->paid);
        $this->assertNull($push->fresh()->processed_at);
    }

    #[Test]
    public function money_the_bank_says_arrived_but_we_will_not_apply_reaches_a_person_once(): void
    {
        // The fare changed after the prompt went out: the bank's receipt is for 150, the booking now says 200.
        $this->fakeCoop(self::livePaid(150, '254798881260'));
        $world = $this->coopWorld();
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);
        Sanctum::actingAs($passenger);
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertOk();
        $push = $this->pushFor($booking);
        $booking->forceFill(['amount' => 200])->save();

        $this->assertSame(CoopStkSettlement::UNKNOWN, app(CoopStkSettlement::class)->check($push));
        $this->assertSame(CoopStkSettlement::UNKNOWN, app(CoopStkSettlement::class)->check($push->fresh()));
        $this->assertFalse((bool) $booking->fresh()->paid);

        // On the super console, with the receipt to chase -- and counted once, however often it is asked about.
        $alert = PlatformNotification::where('event', 'payment.reconciliation.failed')->sole();
        $this->assertSame(1, $alert->data['failureCount']);
        $this->assertSame(150, (int) $alert->data['totalAmount']);
        $this->assertStringContainsString('UKQ4340ZXN', $alert->data['sampleRefs'][0]);
    }

    #[Test]
    public function a_bus_the_bank_has_not_set_up_gets_no_prompt_and_holds_nothing(): void
    {
        // Live: what the bank answered for KDW 322J, which it had left off its STK set-up.
        $this->fakeCoop(push: ['PayResponse' => null]);
        $world = $this->coopWorld();
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);
        Sanctum::actingAs($passenger);

        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])
            ->assertStatus(502)->assertJsonPath('ResponseDescription', 'The bank could not send a payment prompt for this vehicle.')
            ->assertJsonMissingPath('CheckoutRequestID');

        Bus::assertNotDispatched(PollCoopStkPush::class);
        $this->assertTrue(CoopStkSettlement::wasRefused($this->pushFor($booking)));
    }

    #[Test]
    public function a_gateway_fault_is_no_answer_about_the_payment(): void
    {
        // Live refusals: no prompt went out.
        foreach ([['PayResponse' => null], ['MessageCode' => 'E_001', 'MessageDescription' => 'Invalid Operator Code']] as $body) {
            $this->assertTrue((new CoopStkAnswer(200, $body))->refused());
            $this->assertFalse((new CoopStkAnswer(200, $body))->accepted());
        }
        $this->assertTrue((new CoopStkAnswer(200, ['MessageCode' => '0', 'MessageDescription' => 'REQUEST ACCEPTED FOR PROCESSING']))->accepted());

        // A 2xx "fault" envelope, or the allowlist's 403: neither accepts a push nor decides a payment.
        $this->assertFalse((new CoopStkAnswer(200, ['fault' => ['code' => 900901, 'message' => 'Invalid Credentials']]))->accepted());
        $this->assertFalse((new CoopStkAnswer(401, ['fault' => ['code' => 900901]]))->ok());
        $this->assertTrue((new CoopStkAnswer(200, ['MessageCode' => '0']))->accepted());
        $this->assertTrue((new CoopStkAnswer(200, []))->accepted());
        $this->assertFalse((new CoopStkAnswer(200, ['MessageCode' => '-11', 'MessageDescription' => 'DUPLICATE REFERENCE']))->accepted());
        // Refused needs positive evidence; a code we do not know is neither.
        $this->assertTrue((new CoopStkAnswer(200, ['MessageCode' => '-11']))->refused());
        $this->assertTrue((new CoopStkAnswer(400, ['MessageDescription' => 'Bad request']))->refused());
        $unknown = new CoopStkAnswer(200, ['MessageCode' => 'S001']);
        $this->assertFalse($unknown->accepted());
        $this->assertFalse($unknown->refused());
    }

    /** The bank's notification of a fare paid through one of our prompts, as it sent it on 2026-11-26. */
    private function bankNotifies(string $receipt, string $narrationField, string $reference, string $account): void
    {
        config(['services.coop.source_ips' => []]);
        $this->postJson('/api/coop/payments', [
            'AcctNo' => $account, 'Amount' => '150.0', 'Currency' => 'KES', 'EventType' => 'CREDIT',
            'Narration' => $receipt.'~'.$narrationField.'~0798881260~COOP'.$reference,
            'PaymentRef' => '26112026_614620783', 'TransactionDate' => '2026-11-26T11:58:17', 'TransactionId' => 'CB0472949_26112026_2',
        ])->assertOk();
    }

    #[Test]
    public function the_banks_notification_of_a_prompted_fare_lands_on_the_bus(): void
    {
        $this->fakeCoop();
        $world = $this->coopWorld();
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);
        Sanctum::actingAs($passenger);
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertOk();
        $reference = (string) $this->pushFor($booking)->callback_nonce;

        // Now: the narration is the till, matched like any till payment.
        $this->bankNotifies('UKQ43TILL1', '1123852', $reference, '01101233137021');
        $mpesa = Mpesa::withoutGlobalScopes()->where('TransID', 'UKQ43TILL1')->sole();
        $this->assertSame((int) $world['vehicle']->id, (int) Transaction::withoutGlobalScopes()->where('mpesa_id', $mpesa->id)->value('vehicle_id'));
    }

    #[Test]
    public function a_prompt_narrated_the_old_way_is_found_by_its_reference_and_account(): void
    {
        $this->fakeCoop();
        $world = $this->coopWorld();
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);
        Sanctum::actingAs($passenger);
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertOk();
        $reference = (string) $this->pushFor($booking)->callback_nonce;

        $this->bankNotifies('UKQ43OLD01', 'Fare KDW322J', $reference, '01101233137021');
        $mpesa = Mpesa::withoutGlobalScopes()->where('TransID', 'UKQ43OLD01')->sole();
        $this->assertSame('1123852', $mpesa->BusinessShortCode);
        $this->assertSame((int) $world['vehicle']->id, (int) Transaction::withoutGlobalScopes()->where('mpesa_id', $mpesa->id)->value('vehicle_id'));

        // The account must be that bus's: a reference alone moves no money onto a bus.
        $this->bankNotifies('UKQ43OLD02', 'Fare KDW322J', $reference, '01101233139999');
        $other = Mpesa::withoutGlobalScopes()->where('TransID', 'UKQ43OLD02')->sole();
        $this->assertNull(Transaction::withoutGlobalScopes()->where('mpesa_id', $other->id)->value('vehicle_id'));
        // Nor does a made-up reference.
        $this->bankNotifies('UKQ43OLD03', 'Fare KDW322J', 'K000000000000000000', '01101233137021');
        $forged = Mpesa::withoutGlobalScopes()->where('TransID', 'UKQ43OLD03')->sole();
        $this->assertNull(Transaction::withoutGlobalScopes()->where('mpesa_id', $forged->id)->value('vehicle_id'));
    }

    /** The bus's takings: its transactions and its day's summary. */
    private function takings(int $vehicleId): array
    {
        return [
            'transactions' => Transaction::withoutGlobalScopes()->where('vehicle_id', $vehicleId)->count(),
            'summed' => (float) DB::table('summaries')->where('vehicle_id', $vehicleId)->sum('mpesa_amount'),
            'summed_txn' => (int) DB::table('summaries')->where('vehicle_id', $vehicleId)->sum('mpesa_txn'),
        ];
    }

    #[Test]
    public function a_confirmed_fare_is_on_the_buses_takings_at_once_and_the_banks_late_notification_adds_nothing(): void
    {
        $this->fakeCoop(self::livePaid(150, '254798881260'));
        $world = $this->coopWorld();
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);
        Sanctum::actingAs($passenger);
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertOk();
        $push = $this->pushFor($booking);
        $bus = (int) $world['vehicle']->id;

        // Our enquiry proves it: on the takings now, without waiting for the bank.
        $this->runPoll($push);
        $this->assertSame(['transactions' => 1, 'summed' => 150.0, 'summed_txn' => 1], $this->takings($bus));
        $mpesa = Mpesa::withoutGlobalScopes()->where('TransID', 'UKQ4340ZXN')->sole();
        $this->assertSame('1123852', $mpesa->BusinessShortCode);
        $this->assertSame('254798881260', $mpesa->MSISDN);

        // The bank's notification of the same receipt, later -- and again, as it does: nothing more.
        $this->bankNotifies('UKQ4340ZXN', '1123852', (string) $push->callback_nonce, '01101233137021');
        $this->bankNotifies('UKQ4340ZXN', '1123852', (string) $push->callback_nonce, '01101233137021');
        $this->assertSame(['transactions' => 1, 'summed' => 150.0, 'summed_txn' => 1], $this->takings($bus));
        $this->assertSame(1, Mpesa::withoutGlobalScopes()->where('TransID', 'UKQ4340ZXN')->count());
    }

    #[Test]
    public function when_the_bank_tells_us_first_our_confirmation_adds_nothing(): void
    {
        $this->fakeCoop(self::livePaid(150, '254798881260'));
        $world = $this->coopWorld();
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);
        Sanctum::actingAs($passenger);
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertOk();
        $push = $this->pushFor($booking);
        $bus = (int) $world['vehicle']->id;

        $this->bankNotifies('UKQ4340ZXN', '1123852', (string) $push->callback_nonce, '01101233137021');
        $this->assertSame(['transactions' => 1, 'summed' => 150.0, 'summed_txn' => 1], $this->takings($bus));
        $banks = Mpesa::withoutGlobalScopes()->where('TransID', 'UKQ4340ZXN')->sole()->only(['FirstName', 'TransTime', 'BusinessShortCode']);

        $this->runPoll($push);
        $this->assertTrue((bool) $booking->fresh()->paid);
        $this->assertSame(['transactions' => 1, 'summed' => 150.0, 'summed_txn' => 1], $this->takings($bus));
        // The bank's record is left exactly as the bank wrote it.
        $this->assertEquals($banks, Mpesa::withoutGlobalScopes()->where('TransID', 'UKQ4340ZXN')->sole()->only(['FirstName', 'TransTime', 'BusinessShortCode']));
    }

    #[Test]
    public function the_fare_goes_on_the_bus_the_prompt_paid_even_if_the_booking_moved_since(): void
    {
        $this->fakeCoop(self::livePaid(150, '254798881260'));
        $world = $this->coopWorld();
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);
        Sanctum::actingAs($passenger);
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertOk();
        $push = $this->pushFor($booking);

        // While the prompt is open, the booking is moved onto another bus.
        $other = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);
        $other->forceFill(['merchant_short_code' => '1149830', 'coop_operator_code' => 'KDX 392K'])->saveQuietly();
        $queue = $this->makeQueue($other, $world['terminus'], $world['route'], $this->makeQueueStatus('Pending '.$this->nextSequence(), 'Pending'), $world['owner']);
        $booking->forceFill(['queue_id' => $queue->id])->save();

        $this->runPoll($push);

        // The bank's answer says the money went to 1123852's account: that bus's takings, not the other's.
        $this->assertSame(1, $this->takings((int) $world['vehicle']->id)['transactions']);
        $this->assertSame(0, $this->takings((int) $other->id)['transactions']);
    }

    #[Test]
    public function a_fare_confirmed_after_midnight_stays_on_the_day_it_was_paid(): void
    {
        $this->fakeCoop(self::livePaid(150, '254798881260'));
        $world = $this->coopWorld();
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);
        Sanctum::actingAs($passenger);
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertOk();
        $push = $this->pushFor($booking);
        // Prompted at 23:58 Nairobi; our check gets the answer after midnight.
        $push->forceFill(['created_at' => Carbon::parse('2026-11-25 23:58:00', 'Africa/Nairobi')->utc()])->save();

        $this->runPoll($push);

        $this->assertSame('2026-11-25', DB::table('summaries')->where('vehicle_id', $world['vehicle']->id)->value('trans_date'));
    }

    #[Test]
    public function money_we_will_not_apply_is_not_put_on_any_takings_by_us(): void
    {
        // Paid from another number: the person who looks at it decides; the bank's notification records the money.
        $this->fakeCoop(self::livePaid(150, '254700000001'));
        $world = $this->coopWorld();
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);
        Sanctum::actingAs($passenger);
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertOk();

        $this->runPoll($this->pushFor($booking));
        $this->assertSame(0, Mpesa::withoutGlobalScopes()->where('TransID', 'UKQ4340ZXN')->count());
    }
}
