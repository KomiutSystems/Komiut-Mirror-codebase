<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Enums\UserType;
use App\Jobs\PollNcbaStkPush;
use App\Models\Booking;
use App\Models\Mpesa;
use App\Models\MpesaBookingCallback;
use App\Models\MpesaQrcodePayment;
use App\Models\MpesaStkCallback;
use App\Models\QrcodePayment;
use App\Models\User;
use App\Services\Ncba\NcbaStkSettlement;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * NCBA Till STK Push: an in-app payment on a bus that collects on NCBA's
 * paybill 880100, pushed and settled as NCBA's 2024 specification describes.
 * NCBA is Http::fake()d; every assertion is on what was sent to it or on what
 * the passenger's status poll then reads.
 */
final class NcbaStkPushTest extends QueueTestCase
{
    private const PUSH = '/api/v1/auth/mpesa/stk';

    private const QR_PUSH = '/api/v1/auth/qrcode/stk/push';

    private const BASE = 'https://c2bapis.ncbagroup.com/payments/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ncba_stk.username' => 'komiut-stk', 'services.ncba_stk.password' => 'secret-key']);
    }

    /** A bus on NCBA's aggregator: paybill 880100, its own till short code as the account. */
    private function ncbaWorld(string $till = '299106'): array
    {
        $world = $this->makeWorld();
        $world['vehicle']->forceFill(['merchant_short_code' => '880100', 'till_number' => $till, 'financier' => 'NCBA'])->save();

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

    /** @param  array<string, mixed>  $query  what stk-push/query answers */
    private function fakeNcba(array $query = ['status' => 'SUCCESS', 'description' => 'Success'], ?array $initiate = null): void
    {
        Http::fake([
            self::BASE.'/auth/token' => Http::response(['access_token' => 'jwt-1', 'token_type' => 'Bearer', 'expires_in' => 18000, 'status' => 200]),
            self::BASE.'/stk-push/initiate' => Http::response($initiate ?? [
                'TransactionID' => 'NCBA-TX-1', 'StatusCode' => '0', 'StatusDescription' => 'Request accepted', 'ReferenceID' => 'REF-1',
            ]),
            self::BASE.'/stk-push/query' => Http::response($query),
        ]);
    }

    /** Run the poll job the push queued, as the worker would. */
    private function runPoll(MpesaStkCallback $push, int $check = 0): void
    {
        (new PollNcbaStkPush($push->id, $check))->handle(app(NcbaStkSettlement::class));
    }

    #[Test]
    public function a_booking_on_an_ncba_bus_is_pushed_through_ncba_exactly_as_the_spec_says(): void
    {
        $this->fakeNcba();
        $world = $this->ncbaWorld('299106');
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);

        Sanctum::actingAs($passenger);
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])
            ->assertOk()
            // Daraja's field names, so the app polls exactly as it always has.
            ->assertJsonPath('CheckoutRequestID', 'NCBA-TX-1')
            ->assertJsonPath('ResponseCode', '0')
            ->assertJsonPath('provider', 'ncba');

        Http::assertSent(fn (ClientRequest $r) => $r->method() === 'GET' && $r->url() === self::BASE.'/auth/token'
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('komiut-stk:secret-key')));
        Http::assertSent(fn (ClientRequest $r) => $r->url() === self::BASE.'/stk-push/initiate'
            && $r->hasHeader('Authorization', 'Bearer jwt-1')
            && $r['TelephoneNo'] === '254798881260'
            && $r['Amount'] === '150'                  // the server's fare, as a string
            && $r['PayBillNo'] === '880100'
            && $r['AccountNo'] === '299106'            // the bus's own NCBA till
            && $r['Network'] === 'Safaricom'
            && $r['TransactionType'] === 'CustomerPayBillOnline');
        Http::assertNotSent(fn (ClientRequest $r) => str_contains($r->url(), 'safaricom.co.ke'));

        $push = MpesaStkCallback::where('booking_id', $booking->id)->sole();
        $this->assertSame('ncba', $push->provider);
        $this->assertSame('NCBA-TX-1', $push->checkout_request_id);
        $this->assertSame('254798881260', $push->phone);
        Bus::assertDispatched(PollNcbaStkPush::class, fn (PollNcbaStkPush $job) => $job->stkId === $push->id && $job->check === 0);
    }

    #[Test]
    public function a_successful_push_settles_the_booking_like_a_daraja_callback_with_the_real_receipt(): void
    {
        $this->fakeNcba(['status' => 'SUCCESS', 'description' => 'Success']);
        $world = $this->ncbaWorld('299106');
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);
        Sanctum::actingAs($passenger);
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertOk();
        // NCBA's own confirmation of the payment, as it posts every 880100 payment.
        Mpesa::withoutGlobalScopes()->create(['TransID' => 'TKR8ABC123', 'MSISDN' => '254798881260', 'TransAmount' => 150,
            'TransTime' => now(), 'FirstName' => 'Test', 'LastName' => 'Payer', 'BusinessShortCode' => '880100', 'BillRefNumber' => '299106']);

        $this->runPoll(MpesaStkCallback::where('booking_id', $booking->id)->sole());

        $booking->refresh();
        $this->assertTrue((bool) $booking->paid);
        $this->assertSame('mpesa', $booking->payment_method?->value ?? $booking->payment_method);
        $this->assertSame('TKR8ABC123', MpesaBookingCallback::where('booking_id', $booking->id)->value('transid'));
        $this->getJson('/api/auth/mpesa/stk/status/NCBA-TX-1')
            ->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('mpesaReceiptNumber', 'TKR8ABC123');
        Bus::assertDispatchedTimes(PollNcbaStkPush::class, 1);    // settled: no further check queued
    }

    #[Test]
    public function without_the_confirmation_yet_the_ncba_transaction_id_stands_in_for_the_receipt(): void
    {
        $this->fakeNcba(['status' => 'SUCCESS', 'description' => 'Success']);
        $world = $this->ncbaWorld();
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 90);
        Sanctum::actingAs($passenger);
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertOk();

        $this->runPoll(MpesaStkCallback::where('booking_id', $booking->id)->sole());

        $this->assertTrue((bool) $booking->fresh()->paid);
        $this->assertSame('NCBA-NCBA-TX-1', MpesaBookingCallback::where('booking_id', $booking->id)->value('transid'));
    }

    #[Test]
    public function a_failed_push_is_closed_with_ncbas_reason_and_the_booking_stays_unpaid(): void
    {
        $this->fakeNcba(['status' => 'FAILED', 'description' => 'Request cancelled by user']);
        $world = $this->ncbaWorld();
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);
        Sanctum::actingAs($passenger);
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertOk();

        $this->runPoll(MpesaStkCallback::where('booking_id', $booking->id)->sole());

        $this->assertFalse((bool) $booking->fresh()->paid);
        $this->getJson('/api/auth/mpesa/stk/status/NCBA-TX-1')
            ->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('message', 'Request cancelled by user');
        // Closed, so a fresh push for the same booking may go at once.
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertOk()->assertJsonMissing(['replay' => true]);
    }

    #[Test]
    public function a_push_ncba_has_not_decided_is_asked_again_and_stays_processing(): void
    {
        $this->fakeNcba(['status' => 'PENDING', 'description' => 'Awaiting customer']);
        $world = $this->ncbaWorld();
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);
        Sanctum::actingAs($passenger);
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertOk();
        $push = MpesaStkCallback::where('booking_id', $booking->id)->sole();

        $this->runPoll($push, 0);

        Bus::assertDispatched(PollNcbaStkPush::class, fn (PollNcbaStkPush $job) => $job->stkId === $push->id && $job->check === 1);
        $this->assertNull($push->fresh()->processed_at);
        $this->getJson('/api/auth/mpesa/stk/status/NCBA-TX-1')->assertJsonPath('status', 'processing');

        // The last scheduled check stops; payments:reconcile takes over from there.
        $count = count(Bus::dispatched(PollNcbaStkPush::class));
        $this->runPoll($push, count(PollNcbaStkPush::NEXT_CHECKS));
        $this->assertCount($count, Bus::dispatched(PollNcbaStkPush::class));
    }

    #[Test]
    public function a_refused_push_is_a_502_with_ncbas_reason_and_holds_nothing(): void
    {
        $this->fakeNcba(initiate: ['TransactionID' => null, 'StatusCode' => '1', 'StatusDescription' => 'Invalid account number', 'ReferenceID' => null]);
        $world = $this->ncbaWorld();
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);
        Sanctum::actingAs($passenger);

        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])
            ->assertStatus(502)->assertJsonPath('ResponseDescription', 'Invalid account number');

        Bus::assertNotDispatched(PollNcbaStkPush::class);
        $this->assertNull(MpesaStkCallback::where('booking_id', $booking->id)->value('checkout_request_id'));
    }

    #[Test]
    public function a_qr_payment_on_an_ncba_bus_is_pushed_and_settled_with_its_rewards_path(): void
    {
        $this->fakeNcba(['status' => 'SUCCESS', 'description' => 'Success']);
        $world = $this->ncbaWorld('217070');
        $passenger = $this->passenger();
        Sanctum::actingAs($passenger);

        $this->postJson(self::QR_PUSH, ['vehicle_id' => $world['vehicle']->id, 'amount' => 70, 'phone' => '0798881260'])
            ->assertOk()->assertJsonPath('CheckoutRequestID', 'NCBA-TX-1');
        Http::assertSent(fn (ClientRequest $r) => $r->url() === self::BASE.'/stk-push/initiate'
            && $r['Amount'] === '70' && $r['AccountNo'] === '217070');

        $payment = QrcodePayment::where('vehicle_id', $world['vehicle']->id)->sole();
        $this->runPoll(MpesaStkCallback::where('qrcode_payment_id', $payment->id)->sole());

        $this->assertTrue((bool) $payment->fresh()->status);
        $this->assertSame('254798881260', MpesaQrcodePayment::where('qrcode_payment_id', $payment->id)->value('phone'));
    }

    #[Test]
    public function reconcile_settles_an_ncba_push_the_poller_left_open_and_never_asks_daraja_about_it(): void
    {
        // The push went out and its poller never ran (a worker was down).
        $this->fakeNcba(['status' => 'SUCCESS', 'description' => 'Success']);
        $world = $this->ncbaWorld();
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);
        Sanctum::actingAs($passenger);
        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertOk();
        $push = MpesaStkCallback::where('booking_id', $booking->id)->sole();
        $push->forceFill(['created_at' => now()->subMinutes(5)])->save();

        Artisan::call('payments:reconcile');

        $this->assertTrue((bool) $booking->fresh()->paid);
        $this->assertNotNull($push->fresh()->processed_at);
        Http::assertNotSent(fn (ClientRequest $r) => str_contains($r->url(), 'safaricom.co.ke'));
    }

    #[Test]
    public function the_token_is_fetched_once_and_reused(): void
    {
        $this->fakeNcba();
        $world = $this->ncbaWorld();
        $passenger = $this->passenger();
        Sanctum::actingAs($passenger);
        foreach ([150, 120] as $fare) {
            $booking = $this->unpaidBooking($world, $passenger, $fare);
            $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])->assertOk();
        }

        Http::assertSentCount(3);    // one token, two pushes
    }

    #[Test]
    public function without_ncba_credentials_an_ncba_bus_still_refuses_as_before(): void
    {
        config(['services.ncba_stk.username' => null, 'services.ncba_stk.password' => null]);
        Http::fake();
        $world = $this->ncbaWorld();
        $passenger = $this->passenger();
        $booking = $this->unpaidBooking($world, $passenger, 150);
        Sanctum::actingAs($passenger);

        $this->postJson(self::PUSH, ['phone' => '0798881260', 'booking_id' => $booking->id])
            ->assertStatus(422)->assertJsonPath('error', 'This vehicle is not set up for M-Pesa payments yet.');
        Http::assertNothingSent();
    }
}
