<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use App\Enums\WalletEntryKind;
use App\Enums\WalletTopUpRail;
use App\Enums\WalletTopUpStatus;
use App\Models\Mpesa;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTopUp;
use App\Models\WalletTransaction;
use App\Services\Ncba\NcbaStkClient;
use App\Services\Wallet\WalletAccountNumber;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Starting a top-up, and what the passenger is told while it is in flight.
 *
 * The thing these tests mostly defend is a promise NOT being made. A 200 from
 * POST /wallet/top-ups means a prompt was sent, never that money arrived --
 * only a C2B confirmation on the Komiut float shortcode credits a wallet. So a
 * pending attempt must stay pending even when Safaricom says the customer paid,
 * and nothing on this path may ever move a balance.
 *
 * Covers WalletController (topUp / topUpStatus), WalletTopUpService (start,
 * applyRailVerdict, expireStaleAttempts) and the unauthenticated callback.
 */
final class WalletTopUpTest extends QueueTestCase
{
    private const TOP_UPS = '/api/v1/auth/wallet/top-ups';

    private const WALLET = '/api/v1/auth/wallet';

    private function passenger(): User
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Provision the float account the way a real deployment would.
     *
     * Without this NOTHING can credit a wallet -- which is the shipped state
     * and is itself tested below. Every test that wants a prompt has to opt in,
     * so no test can accidentally pass because a rail was live by default.
     */
    private function provisionFloat(): void
    {
        config()->set('wallet.float.short_code', '400200');
        config()->set('wallet.float.consumer_key', 'ck');
        config()->set('wallet.float.consumer_secret', 'cs');
        config()->set('wallet.float.pass_key', 'pk');
        config()->set('wallet.top_up_sources', [
            ['id' => 'mpesa', 'available' => true],
            ['id' => 'ncba', 'available' => false],
        ]);
    }

    /**
     * No test here may touch the real Safaricom.
     *
     * Http::fake() with only specific patterns lets anything UNMATCHED through
     * to the live network -- which is how a test here spent ten seconds calling
     * api.safaricom.co.ke once the recovery sweep started issuing stkQuery.
     *
     * preventStrayRequests() rather than a catch-all stub, because Http::fake()
     * MERGES across calls instead of replacing: a catch-all registered by the
     * first fake shadows every stub a later one adds, which silently turned
     * seven of these tests red by answering 500 to their stkQuery.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /** Daraja accepting a push. */
    private function fakeDarajaAccepts(): void
    {
        Http::fake([
            '*oauth/v1/generate*' => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            '*stkpush/v1/processrequest*' => Http::response([
                'ResponseCode' => '0',
                'CheckoutRequestID' => 'ws_CO_12345',
                'CustomerMessage' => 'Success. Request accepted',
            ]),
        ]);
    }

    /** Daraja's stkQuery confirming the payment really happened. */
    private function fakeDarajaConfirms(): void
    {
        Http::fake([
            '*oauth/v1/generate*' => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            '*stkpushquery/v1/query*' => Http::response(['ResultCode' => '0', 'ResultDesc' => 'The service request is processed successfully.']),
        ]);
    }

    /** Daraja 500ing, which it does routinely -- stkQuery returns null. */
    private function fakeDarajaUnreachable(): void
    {
        Http::fake([
            '*oauth/v1/generate*' => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            '*stkpushquery/v1/query*' => Http::response([], 500),
        ]);
    }

    /** Daraja saying it never happened -- what a forged callback runs into. */
    private function fakeDarajaDenies(): void
    {
        Http::fake([
            '*oauth/v1/generate*' => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            '*stkpushquery/v1/query*' => Http::response(['ResultCode' => '1037', 'ResultDesc' => 'No such request']),
        ]);
    }

    #[Test]
    public function with_no_float_account_the_wallet_advertises_no_way_to_top_up(): void
    {
        // The shipped state. A source advertised as available with no float
        // account behind it is a Top Up button that charges a passenger and
        // credits nothing, so availability is computed rather than read from
        // config.
        config()->set('wallet.top_up_sources', [
            ['id' => 'mpesa', 'available' => true],
            ['id' => 'ncba', 'available' => true],
        ]);
        config()->set('wallet.float.short_code', null);

        $this->passenger();

        $this->getJson(self::WALLET)->assertOk()->assertJsonPath('wallet.top_up_sources', [
            ['id' => 'mpesa', 'available' => false],
            ['id' => 'ncba', 'available' => false],
        ]);
    }

    #[Test]
    public function a_top_up_on_an_unprovisioned_rail_is_refused_not_prompted(): void
    {
        Http::fake();
        $this->passenger();

        $this->postJson(self::TOP_UPS, ['amount' => 500, 'source' => 'mpesa'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'source_unavailable');

        $this->assertSame(0, WalletTopUp::count(), 'nothing is recorded for a rail that cannot take money');
        Http::assertNothingSent();
    }

    #[Test]
    public function starting_a_top_up_prompts_the_handset_and_stays_pending(): void
    {
        $this->provisionFloat();
        $this->fakeDarajaAccepts();
        $this->passenger();

        $body = $this->postJson(self::TOP_UPS, ['amount' => 500, 'source' => 'mpesa', 'phone' => '0712345678'])
            ->assertOk()
            ->json('top_up');

        $this->assertSame(500, $body['amount']);
        $this->assertSame('mpesa', $body['source']);
        $this->assertSame('pending', $body['status'], 'a prompt was sent; no money has arrived');
        $this->assertNull($body['balance_after'], 'nothing is credited yet');
        $this->assertSame('254712345678', $body['phone'], 'normalised to an msisdn');

        $attempt = WalletTopUp::firstOrFail();
        $this->assertSame('ws_CO_12345', $attempt->rail_reference);
        $this->assertNotNull($attempt->callback_nonce);

        // AND NO MONEY MOVED.
        $this->assertSame(0, (int) Wallet::firstOrFail()->balance_cents);
    }

    #[Test]
    public function the_push_carries_the_wallet_account_number_as_its_reference(): void
    {
        // The AccountReference is what comes back as BillRefNumber on the
        // confirmation, and it is the ONLY thing that says which wallet the
        // money belongs to. If it were anything else the payment would arrive
        // unattributable.
        $this->provisionFloat();
        $this->fakeDarajaAccepts();
        $this->passenger();

        $this->postJson(self::TOP_UPS, ['amount' => 500, 'source' => 'mpesa'])->assertOk();

        $accountNo = Wallet::firstOrFail()->account_no;
        $this->assertNotNull($accountNo);

        Http::assertSent(function ($request) use ($accountNo) {
            if (! str_contains($request->url(), 'stkpush/v1/processrequest')) {
                return false;
            }

            return $request['AccountReference'] === $accountNo
                && (int) $request['Amount'] === 500
                && (int) $request['BusinessShortCode'] === 400200;
        });
    }

    #[Test]
    public function a_refused_push_leaves_a_failed_attempt_the_passenger_can_see(): void
    {
        $this->provisionFloat();
        Http::fake([
            '*oauth/v1/generate*' => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            '*stkpush/v1/processrequest*' => Http::response([
                'requestId' => 'x',
                'errorMessage' => 'Bad Request - Invalid PhoneNumber',
            ], 400),
        ]);
        $this->passenger();

        $body = $this->postJson(self::TOP_UPS, ['amount' => 500, 'source' => 'mpesa'])->assertOk()->json('top_up');

        // FAILED, not pending. Pending means "there is a prompt in front of
        // you", and claiming that when none was sent leaves the passenger
        // watching a spinner until it times out.
        $this->assertSame('failed', $body['status']);
        $this->assertSame('Bad Request - Invalid PhoneNumber', $body['failure_reason']);
    }

    #[Test]
    public function a_double_tapped_top_up_button_prompts_once(): void
    {
        $this->provisionFloat();
        $this->fakeDarajaAccepts();
        $this->passenger();

        $first = $this->withHeaders(['Idempotency-Key' => 'tap-once'])
            ->postJson(self::TOP_UPS, ['amount' => 500, 'source' => 'mpesa'])->assertOk()->json('top_up.id');

        $second = $this->withHeaders(['Idempotency-Key' => 'tap-once'])
            ->postJson(self::TOP_UPS, ['amount' => 500, 'source' => 'mpesa'])->assertOk()->json('top_up.id');

        $this->assertSame($first, $second, 'the same attempt comes back');
        $this->assertSame(1, WalletTopUp::count());

        // One prompt on the handset, not two.
        Http::assertSentCount(2); // one token fetch + one push
    }

    #[Test]
    public function an_idempotency_key_cannot_reach_another_passengers_attempt(): void
    {
        $this->provisionFloat();
        $this->fakeDarajaAccepts();

        $alice = $this->makeUser();
        Sanctum::actingAs($alice);
        $first = $this->withHeaders(['Idempotency-Key' => 'shared'])
            ->postJson(self::TOP_UPS, ['amount' => 500, 'source' => 'mpesa'])->assertOk();

        $bob = $this->makeUser();
        Sanctum::actingAs($bob);

        // Bob gets HIS OWN attempt, not Alice's. The key is namespaced to the
        // caller, so an identical header is not a collision at all -- which is
        // better than the 422 this used to answer: that refusal was itself a
        // yes/no oracle on whether a given key belonged to somebody else.
        $second = $this->withHeaders(['Idempotency-Key' => 'shared'])
            ->postJson(self::TOP_UPS, ['amount' => 500, 'source' => 'mpesa'])->assertOk();

        $this->assertNotSame($first->json('top_up.id'), $second->json('top_up.id'));
        $this->assertSame(2, WalletTopUp::count());

        $keys = WalletTopUp::orderBy('id')->pluck('idempotency_key')->all();
        $this->assertSame(['client:'.$alice->id.':shared', 'client:'.$bob->id.':shared'], $keys);
    }

    #[Test]
    public function a_client_key_cannot_squat_the_row_a_real_paybill_payment_needs(): void
    {
        // The server mints `c2b:{mpesa_id}` into the same unique column. A
        // passenger sending that header used to take the row, so when the real
        // manual payment for that mpesa id arrived, firstOrCreate found the
        // squatted row and the genuine payer's top-up history lost it.
        $this->provisionFloat();
        $this->fakeDarajaAccepts();

        $alice = $this->makeUser();
        Sanctum::actingAs($alice);
        $this->withHeaders(['Idempotency-Key' => 'c2b:91234'])
            ->postJson(self::TOP_UPS, ['amount' => 500, 'source' => 'mpesa'])->assertOk();

        $this->assertSame(
            'client:'.$alice->id.':c2b:91234',
            (string) WalletTopUp::firstOrFail()->idempotency_key,
            'the c2b namespace is the server-s alone'
        );
        $this->assertSame(0, WalletTopUp::where('idempotency_key', 'c2b:91234')->count());
    }

    #[Test]
    public function the_amount_is_bounded_server_side(): void
    {
        config()->set('wallet.limits.min_top_up', 50);
        config()->set('wallet.limits.max_top_up', 20000);
        $this->provisionFloat();
        Http::fake();
        $this->passenger();

        foreach ([49, 20001] as $amount) {
            $this->postJson(self::TOP_UPS, ['amount' => $amount, 'source' => 'mpesa'])
                ->assertStatus(422)
                ->assertJsonPath('code', 'amount_out_of_range');
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function a_top_up_that_could_not_be_held_is_refused_before_the_prompt(): void
    {
        // Refused BEFORE prompting, because the alternative is taking a
        // passenger's money and then declining to hold it -- the credit would
        // fail with the cash already gone from their M-Pesa.
        config()->set('wallet.limits.max_balance', 1000);
        $this->provisionFloat();
        Http::fake();

        $user = $this->passenger();
        app(WalletService::class)->credit(
            (int) $user->id, 90000, WalletEntryKind::TopUp, 'seed'
        );

        $this->postJson(self::TOP_UPS, ['amount' => 500, 'source' => 'mpesa'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'max_balance');

        Http::assertNothingSent();
    }

    #[Test]
    public function a_passenger_can_only_poll_their_own_top_up(): void
    {
        $this->provisionFloat();
        $this->fakeDarajaAccepts();

        $alice = $this->makeUser();
        Sanctum::actingAs($alice);
        $id = $this->postJson(self::TOP_UPS, ['amount' => 500, 'source' => 'mpesa'])->assertOk()->json('top_up.id');

        $this->getJson(self::TOP_UPS.'/'.$id)->assertOk()->assertJsonPath('top_up.id', $id);

        $bob = $this->makeUser();
        Sanctum::actingAs($bob);

        // 404 rather than 403: confirming that someone else's top-up exists
        // leaks that it does.
        $this->getJson(self::TOP_UPS.'/'.$id)->assertNotFound();
    }

    #[Test]
    public function the_callback_credits_a_verified_payment(): void
    {
        // THIS is where an STK top-up is credited. A Lipa-na-M-Pesa-Online
        // payment is delivered to the STK CallBackURL and never reaches the C2B
        // confirmation URL -- which is why MpesaPaymentsController::stkResponse
        // settles bookings from CallbackMetadata too. Waiting for a
        // confirmation that never comes would mean the passenger pays and is
        // never credited.
        $this->provisionFloat();
        $this->fakeDarajaAccepts();
        $user = $this->passenger();

        $this->postJson(self::TOP_UPS, ['amount' => 500, 'source' => 'mpesa'])->assertOk();
        $attempt = WalletTopUp::firstOrFail();

        $this->fakeDarajaConfirms();

        $this->postJson('/api/wallet/top-ups/'.$attempt->callback_nonce.'/callback', [
            'Body' => ['stkCallback' => [
                'ResultCode' => 0,
                'ResultDesc' => 'The service request is processed successfully.',
                'CallbackMetadata' => ['Item' => [
                    ['Name' => 'Amount', 'Value' => 500],
                    ['Name' => 'MpesaReceiptNumber', 'Value' => 'TGX1234567'],
                    ['Name' => 'TransactionDate', 'Value' => '20261108120000'],
                    ['Name' => 'PhoneNumber', 'Value' => 254712345678],
                ]],
            ]],
        ])->assertOk()->assertJsonPath('ResultCode', 0);

        $this->assertSame(50000, app(WalletService::class)->balanceCents((int) $user->id));

        $attempt->refresh();
        $this->assertSame(WalletTopUpStatus::Completed, $attempt->status);
        $this->assertNotNull($attempt->wallet_transaction_id);

        // The ledger row names the payment, so the cross-rail guard still holds
        // if a confirmation for the same receipt turns up later.
        $entry = WalletTransaction::firstOrFail();
        $this->assertNotNull($entry->mpesa_id);
        $this->assertSame('TGX1234567', Mpesa::withoutGlobalScopes()->findOrFail($entry->mpesa_id)->TransID);
    }

    #[Test]
    public function an_unverifiable_success_callback_credits_nothing(): void
    {
        // THE FORGED-CALLBACK DEFENCE. The endpoint is unauthenticated because
        // Safaricom presents no credential, so a POST naming an invented
        // receipt would otherwise mint an mpesas row and credit a wallet with
        // money that never existed. Daraja's stkQuery answers over a channel a
        // forgery cannot reach, and it fails CLOSED.
        $this->provisionFloat();
        $this->fakeDarajaAccepts();
        $user = $this->passenger();

        $this->postJson(self::TOP_UPS, ['amount' => 500, 'source' => 'mpesa'])->assertOk();
        $attempt = WalletTopUp::firstOrFail();

        $this->fakeDarajaDenies();

        $this->postJson('/api/wallet/top-ups/'.$attempt->callback_nonce.'/callback', [
            'Body' => ['stkCallback' => [
                'ResultCode' => 0,
                'CallbackMetadata' => ['Item' => [
                    ['Name' => 'Amount', 'Value' => 500],
                    ['Name' => 'MpesaReceiptNumber', 'Value' => 'FORGED001'],
                ]],
            ]],
        ])->assertOk();

        $this->assertSame(0, app(WalletService::class)->balanceCents((int) $user->id));
        $this->assertSame(0, WalletTransaction::count());
        $this->assertNull(Mpesa::withoutGlobalScopes()->where('TransID', 'FORGED001')->first());

        // Left PENDING, not failed: telling a passenger who really did pay that
        // they had not is worse than making them wait for the sweep.
        $this->assertSame(WalletTopUpStatus::Pending, $attempt->refresh()->status);
    }

    #[Test]
    public function a_callback_cannot_inflate_the_amount_it_credits(): void
    {
        // An STK prompt charges exactly what was requested -- the payer cannot
        // edit it -- so the figure sent at initiation is authoritative and the
        // body's is ignored. Otherwise a forged callback would simply ask for
        // more.
        $this->provisionFloat();
        $this->fakeDarajaAccepts();
        $user = $this->passenger();

        $this->postJson(self::TOP_UPS, ['amount' => 500, 'source' => 'mpesa'])->assertOk();
        $attempt = WalletTopUp::firstOrFail();

        $this->fakeDarajaConfirms();

        $this->postJson('/api/wallet/top-ups/'.$attempt->callback_nonce.'/callback', [
            'Body' => ['stkCallback' => [
                'ResultCode' => 0,
                'CallbackMetadata' => ['Item' => [
                    ['Name' => 'Amount', 'Value' => 99999],
                    ['Name' => 'MpesaReceiptNumber', 'Value' => 'TGXGREEDY1'],
                ]],
            ]],
        ])->assertOk();

        $this->assertSame(50000, app(WalletService::class)->balanceCents((int) $user->id), 'credited what we prompted for');
    }

    #[Test]
    public function the_callback_can_cancel_a_prompt_without_crediting(): void
    {
        $this->provisionFloat();
        $this->fakeDarajaAccepts();
        $user = $this->passenger();

        $this->postJson(self::TOP_UPS, ['amount' => 500, 'source' => 'mpesa'])->assertOk();
        $attempt = WalletTopUp::firstOrFail();

        $this->postJson('/api/wallet/top-ups/'.$attempt->callback_nonce.'/callback', [
            'Body' => ['stkCallback' => [
                'ResultCode' => 1032,
                'ResultDesc' => 'Request cancelled by user',
            ]],
        ])->assertOk()->assertJsonPath('ResultCode', 0);

        $attempt->refresh();
        $this->assertSame(WalletTopUpStatus::Cancelled, $attempt->status);
        // Passenger copy, not Safaricom's ResultDesc -- see failureCopy().
        $this->assertSame('You cancelled the M-Pesa prompt. No money left your account.', $attempt->failure_reason);
        $this->assertSame(0, app(WalletService::class)->balanceCents((int) $user->id));
    }

    #[Test]
    public function a_replayed_success_callback_credits_once(): void
    {
        $this->provisionFloat();
        $this->fakeDarajaAccepts();
        $user = $this->passenger();

        $this->postJson(self::TOP_UPS, ['amount' => 500, 'source' => 'mpesa'])->assertOk();
        $attempt = WalletTopUp::firstOrFail();

        $this->fakeDarajaConfirms();

        $body = [
            'Body' => ['stkCallback' => [
                'ResultCode' => 0,
                'CallbackMetadata' => ['Item' => [
                    ['Name' => 'Amount', 'Value' => 500],
                    ['Name' => 'MpesaReceiptNumber', 'Value' => 'TGXREPLAY1'],
                ]],
            ]],
        ];

        $url = '/api/wallet/top-ups/'.$attempt->callback_nonce.'/callback';
        $this->postJson($url, $body)->assertOk();
        $this->postJson($url, $body)->assertOk();

        $this->assertSame(50000, app(WalletService::class)->balanceCents((int) $user->id));
        $this->assertSame(1, WalletTransaction::count());
    }

    #[Test]
    public function a_payment_whose_callback_never_verified_is_recovered_not_lost(): void
    {
        // AN STK PAYMENT HAS NO OTHER PATH TO US. Without the recovery sweep, a
        // stkQuery that was briefly unreachable when the callback landed means
        // the passenger paid and is never credited -- and nothing notices,
        // because wallet:reconcile only sees money that reached the ledger.
        $this->provisionFloat();
        $user = $this->passenger();

        // ONE fake, with a SEQUENCE for the query, because Http::fake() merges
        // instead of replacing: a second fake cannot change how an already
        // registered pattern answers. First query 500s -- Daraja is down when
        // the callback lands -- and the second, which the sweep makes,
        // succeeds.
        Http::fake([
            '*oauth/v1/generate*' => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            '*stkpush/v1/processrequest*' => Http::response([
                'ResponseCode' => '0',
                'CheckoutRequestID' => 'ws_CO_12345',
            ]),
            '*stkpushquery/v1/query*' => Http::sequence()
                ->push([], 500)
                ->push(['ResultCode' => '0', 'ResultDesc' => 'The service request is processed successfully.']),
        ]);

        $this->postJson(self::TOP_UPS, ['amount' => 500, 'source' => 'mpesa'])->assertOk();
        $attempt = WalletTopUp::firstOrFail();

        $this->postJson('/api/wallet/top-ups/'.$attempt->callback_nonce.'/callback', [
            'Body' => ['stkCallback' => [
                'ResultCode' => 0,
                'CallbackMetadata' => ['Item' => [
                    ['Name' => 'Amount', 'Value' => 500],
                    ['Name' => 'MpesaReceiptNumber', 'Value' => 'TGXLATE001'],
                    ['Name' => 'TransactionDate', 'Value' => '20261108120000'],
                ]],
            ]],
        ])->assertOk();

        $this->assertSame(0, app(WalletService::class)->balanceCents((int) $user->id), 'not credited yet');

        // But the receipt WAS kept, which is the only trace the money exists.
        $attempt->refresh();
        $this->assertSame('TGXLATE001', $attempt->rail_receipt);
        $this->assertSame(WalletTopUpStatus::Pending, $attempt->status);

        // Daraja comes back (the sequence's second answer) and the sweep
        // finishes the job.
        $this->artisan('wallet:settle-top-ups')->assertSuccessful();

        $this->assertSame(50000, app(WalletService::class)->balanceCents((int) $user->id));
        $this->assertSame(WalletTopUpStatus::Completed, $attempt->refresh()->status);
        $this->assertSame('stk_recovery', WalletTransaction::firstOrFail()->meta['via']);
    }

    #[Test]
    public function a_payment_whose_callback_never_arrived_is_still_recovered(): void
    {
        // No callback at all -- the most common lost case. stkQuery returns the
        // receipt itself, which is why ReconcileMpesaPayments can settle
        // bookings the same way.
        $this->provisionFloat();
        $this->fakeDarajaAccepts();
        $user = $this->passenger();

        $this->postJson(self::TOP_UPS, ['amount' => 500, 'source' => 'mpesa'])->assertOk();
        $attempt = WalletTopUp::firstOrFail();

        // It has already been aged out by the time anyone looks.
        $attempt->forceFill([
            'created_at' => now()->subMinutes(40),
            'status' => WalletTopUpStatus::TimedOut,
        ])->save();

        Http::fake([
            '*oauth/v1/generate*' => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            '*stkpushquery/v1/query*' => Http::response([
                'ResultCode' => '0',
                'ResultDesc' => 'The service request is processed successfully.',
                'MpesaReceiptNumber' => 'TGXNOCB001',
            ]),
        ]);

        $this->artisan('wallet:settle-top-ups')->assertSuccessful();

        // A timed-out attempt is still settleable: the timeout was a guess
        // about the prompt, and the money is not a guess.
        $this->assertSame(50000, app(WalletService::class)->balanceCents((int) $user->id));
        $this->assertSame(WalletTopUpStatus::Completed, $attempt->refresh()->status);
    }

    #[Test]
    public function the_recovery_sweep_credits_once_however_often_it_runs(): void
    {
        $this->provisionFloat();
        $this->fakeDarajaAccepts();
        $user = $this->passenger();

        $this->postJson(self::TOP_UPS, ['amount' => 500, 'source' => 'mpesa'])->assertOk();

        Http::fake([
            '*oauth/v1/generate*' => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            '*stkpushquery/v1/query*' => Http::response(['ResultCode' => '0', 'MpesaReceiptNumber' => 'TGXTWICE01']),
        ]);

        $this->artisan('wallet:settle-top-ups')->assertSuccessful();
        $this->artisan('wallet:settle-top-ups')->assertSuccessful();

        $this->assertSame(50000, app(WalletService::class)->balanceCents((int) $user->id));
        $this->assertSame(1, WalletTransaction::count());
    }

    #[Test]
    public function an_authoritative_refusal_closes_the_attempt(): void
    {
        // A silence is not a no, but a refusal IS -- so this one may be closed.
        $this->provisionFloat();
        $this->fakeDarajaAccepts();
        $user = $this->passenger();

        $this->postJson(self::TOP_UPS, ['amount' => 500, 'source' => 'mpesa'])->assertOk();

        Http::fake([
            '*oauth/v1/generate*' => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            '*stkpushquery/v1/query*' => Http::response(['ResultCode' => '1032', 'ResultDesc' => 'Request cancelled by user']),
        ]);

        $this->artisan('wallet:settle-top-ups')->assertSuccessful();

        $this->assertSame(WalletTopUpStatus::Cancelled, WalletTopUp::firstOrFail()->status);
        $this->assertSame(0, app(WalletService::class)->balanceCents((int) $user->id));
    }

    #[Test]
    public function an_unreachable_daraja_never_closes_an_attempt(): void
    {
        // Fails CLOSED in both directions: no credit, and no refusal either.
        // Marking it failed on a silence would tell a passenger who paid that
        // they had not.
        $this->provisionFloat();
        $this->fakeDarajaAccepts();
        $this->passenger();

        $this->postJson(self::TOP_UPS, ['amount' => 500, 'source' => 'mpesa'])->assertOk();

        $this->fakeDarajaUnreachable();
        $this->artisan('wallet:settle-top-ups')->assertSuccessful();

        $this->assertSame(WalletTopUpStatus::Pending, WalletTopUp::firstOrFail()->status);
        $this->assertSame(0, WalletTransaction::count());
    }

    #[Test]
    public function an_unknown_callback_nonce_is_accepted_without_erroring(): void
    {
        // A non-2xx makes Safaricom retry, and a 500 on a payment webhook is
        // how the legacy backend lost 52 payments.
        $this->postJson('/api/wallet/top-ups/'.str_repeat('a', 64).'/callback', [
            'Body' => ['stkCallback' => ['ResultCode' => 1032, 'ResultDesc' => 'nope']],
        ])->assertOk()->assertJsonPath('ResultCode', 0);
    }

    #[Test]
    public function the_callback_route_refuses_anything_that_is_not_a_nonce(): void
    {
        // The path is constrained to 64 hex characters, so a sequential id
        // cannot be walked.
        $this->postJson('/api/wallet/top-ups/7/callback', [])->assertNotFound();
    }

    #[Test]
    public function an_unanswered_prompt_times_out_rather_than_failing(): void
    {
        config()->set('wallet.top_up_expiry_minutes', 15);
        $this->provisionFloat();
        $this->fakeDarajaAccepts();
        $this->passenger();

        $this->postJson(self::TOP_UPS, ['amount' => 500, 'source' => 'mpesa'])->assertOk();

        $attempt = WalletTopUp::firstOrFail();
        $attempt->forceFill(['created_at' => now()->subMinutes(20)])->save();

        // Daraja cannot confirm it, so the recovery half of the sweep leaves it
        // alone and the expiry half is what acts. A silence is never a refusal.
        $this->fakeDarajaUnreachable();

        $this->artisan('wallet:settle-top-ups')->assertSuccessful();

        // timed_out, NOT failed: a late payment must still be able to settle
        // this attempt, which is what mayStillSettle() protects.
        $this->assertSame(WalletTopUpStatus::TimedOut, $attempt->refresh()->status);
        $this->assertTrue($attempt->status->mayStillSettle());
    }

    #[Test]
    public function an_account_number_survives_being_typed_badly(): void
    {
        $user = $this->makeUser();
        $wallet = app(WalletService::class)->lock((int) $user->id);
        $code = WalletAccountNumber::forWallet($wallet);

        // People insert spaces and dashes and some handsets send lower case. A
        // top-up that failed to match because of that is real money sitting
        // unattributed.
        $this->assertSame($code, WalletAccountNumber::normalise(strtolower($code)));
        $this->assertSame($code, WalletAccountNumber::normalise(substr($code, 0, 3).' '.substr($code, 3)));
        $this->assertSame($code, WalletAccountNumber::normalise(substr($code, 0, 3).'-'.substr($code, 3)));

        // And it must never collide with a fare reference. Every paybill
        // payment carries a BillRefNumber too.
        $this->assertNull(WalletAccountNumber::normalise('KDL126J'));
        $this->assertNull(WalletAccountNumber::normalise('W'), 'too short');
        $this->assertNull(WalletAccountNumber::normalise('WOOOOOO'), 'O is not in the alphabet');
        $this->assertNull(WalletAccountNumber::normalise('299106'), 'an NCBA till is not a wallet');
        $this->assertNull(WalletAccountNumber::normalise(null));
    }

    #[Test]
    public function an_account_number_avoids_the_characters_people_mistype(): void
    {
        $user = $this->makeUser();
        $wallet = app(WalletService::class)->lock((int) $user->id);

        $code = WalletAccountNumber::forWallet($wallet);

        $this->assertMatchesRegularExpression('/^W[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{6}$/', $code);

        // Minted once and kept: it is printed on a screen and typed into a
        // paybill, so it cannot change under a passenger.
        $this->assertSame($code, WalletAccountNumber::forWallet($wallet->refresh()));
    }

    #[Test]
    public function the_ncba_rail_is_off_even_with_the_bank_credentials_in_place(): void
    {
        // HARD OFF, not merely unconfigured -- and credentials-present is
        // exactly the case that matters, because provisioning NCBA_STK for the
        // FARE pilot must not switch this rail on as a side effect.
        //
        // NcbaStkClient pushes on PayBillNo 880100, NCBA's aggregator shared by
        // every SACCO's till, so the confirmation arrives with
        // BusinessShortCode=880100. Either ownsShortCode() rejects it and the
        // passenger pays for nothing, or 880100 is named as the float and the
        // security gate is gone, because every till's fare money then also
        // "arrived on the float shortcode". One shortcode cannot gate both rails.
        config()->set('wallet.float.short_code', '400200');
        config()->set('wallet.float.consumer_key', 'ck');
        config()->set('wallet.float.consumer_secret', 'cs');
        config()->set('wallet.float.pass_key', 'pk');
        config()->set('wallet.top_up_sources', [['id' => 'ncba', 'available' => true]]);

        // The bank credentials ARE set, so NcbaStkClient::enabled() is true.
        config()->set('services.ncba_stk.username', 'KOMIUT');
        config()->set('services.ncba_stk.password', 'secret');
        $this->assertTrue(app(NcbaStkClient::class)->enabled());

        $this->passenger();

        $this->getJson(self::WALLET)->assertOk()
            ->assertJsonPath('wallet.top_up_sources', [['id' => 'ncba', 'available' => false]]);

        Http::fake();
        $this->postJson(self::TOP_UPS, ['amount' => 500, 'source' => 'ncba'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'source_unavailable');
        Http::assertNothingSent();

        // And the reason anything awaiting an NCBA callback waits forever.
        $this->assertFalse(WalletTopUpRail::Ncba->hasCallback(), 'NCBA never calls back; do not wait for one');
        $this->assertTrue(WalletTopUpRail::Mpesa->hasCallback());
    }

    #[Test]
    public function one_uncreditable_attempt_does_not_strand_every_later_one(): void
    {
        // THE BUG THIS PINS. The recovery loop had no per-attempt catch, so the
        // first attempt that could not be credited threw, the exception escaped
        // the loop AND the command, and every LATER passenger's lost payment
        // stayed lost -- permanently, because the sweep is the only path an STK
        // payment has to us and nothing else looks for one.
        //
        // Passenger A's balance has since passed the ceiling... except the
        // ceiling no longer refuses arrived money, so the modern way to make one
        // attempt fail is to point it at a wallet that has been deleted from
        // under it. Either way the property is the same: B must still be paid.
        config()->set('wallet.limits.max_balance', 50000);
        $this->provisionFloat();

        [$alice, $bob] = [$this->passenger(), null];
        $aliceAttempt = $this->startedAttempt($alice, 500, 'ws_CO_ALICE');

        $bob = $this->passenger();
        $bobAttempt = $this->startedAttempt($bob, 300, 'ws_CO_BOB');

        // Alice's wallet goes away, so crediting her attempt throws.
        Wallet::whereKey($aliceAttempt->wallet_id)->delete();

        Http::fake([
            '*oauth/v1/generate*' => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            '*stkpushquery/v1/query*' => Http::response(['ResultCode' => '0', 'MpesaReceiptNumber' => 'TGXISOL01']),
        ]);

        // The command SUCCEEDS rather than blowing up, and says what it could
        // not finish.
        $this->artisan('wallet:settle-top-ups')->assertSuccessful();

        // BOB IS PAID. Before the fix his attempt was never reached.
        $bobAttempt->refresh();
        $this->assertNotNull($bobAttempt->wallet_transaction_id, 'the later attempt was still recovered');
        $this->assertSame(30000, app(WalletService::class)->balanceCents((int) $bob->id));
    }

    #[Test]
    public function one_payment_cannot_be_credited_under_two_trans_ids(): void
    {
        // The callback keys the payment on the receipt; the sweep may key it on
        // 'stk-{rail_reference}' when stkQuery returns none. Two TransIDs mean
        // two `mpesas` rows and two mpesa_ids, and BOTH pass the unique-mpesa_id
        // guard -- so one payment could credit twice while every reconcile check
        // stayed silent, because each credit traced to a real payment row of the
        // right amount.
        $this->provisionFloat();
        $user = $this->passenger();
        $attempt = $this->startedAttempt($user, 500, 'ws_CO_TWICE');

        // First: the sweep, with NO receipt -> keys on stk-{ref}.
        Http::fake([
            '*oauth/v1/generate*' => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            '*stkpushquery/v1/query*' => Http::response(['ResultCode' => '0']),
        ]);
        $this->artisan('wallet:settle-top-ups')->assertSuccessful();

        $this->assertSame(50000, app(WalletService::class)->balanceCents((int) $user->id));
        $this->assertSame(1, WalletTransaction::where('kind', WalletEntryKind::TopUp->value)->count());

        // AND the `stk-{ref}` stand-in never reaches the passenger: it is not a
        // number anybody can look up, so neither the ledger line nor the
        // receipt may quote it.
        $credit = WalletTransaction::where('kind', WalletEntryKind::TopUp->value)->firstOrFail();
        $this->assertNull($credit->detail);
        $this->assertStringStartsWith('WE', $credit->receiptCode());

        // Then: the callback arrives late WITH a receipt. A different TransID,
        // so the mpesa_id guard cannot catch it -- only the settled check can.
        $this->postJson('/api/wallet/top-ups/'.$attempt->callback_nonce.'/callback', [
            'Body' => ['stkCallback' => [
                'ResultCode' => 0,
                'CallbackMetadata' => ['Item' => [
                    ['Name' => 'Amount', 'Value' => 500],
                    ['Name' => 'MpesaReceiptNumber', 'Value' => 'TGXLATE99'],
                ]],
            ]],
        ])->assertOk();

        $this->assertSame(50000, app(WalletService::class)->balanceCents((int) $user->id), 'credited once');
        $this->assertSame(1, WalletTransaction::where('kind', WalletEntryKind::TopUp->value)->count());
    }

    /**
     * A pending mpesa attempt with a rail reference, as a real prompt leaves one.
     *
     * Built directly rather than through the endpoint so the test does not also
     * depend on the push succeeding.
     */
    private function startedAttempt(User $user, int $shillings, string $railReference): WalletTopUp
    {
        $wallet = app(WalletService::class)->lock((int) $user->id);

        return WalletTopUp::create([
            'wallet_id' => $wallet->id,
            'amount_cents' => $shillings * 100,
            'rail' => WalletTopUpRail::Mpesa,
            'status' => WalletTopUpStatus::Pending,
            'phone' => '254712345678',
            'idempotency_key' => 'started:'.$railReference,
            'rail_reference' => $railReference,
            'callback_nonce' => str_pad(dechex(crc32($railReference)), 64, '0'),
        ]);
    }

    #[Test]
    public function a_failure_callback_cannot_strand_a_payment_we_have_a_receipt_for(): void
    {
        // THE ATTACK AND THE ACCIDENT, same shape. An attempt that already holds
        // a rail_receipt had a success callback name a real M-Pesa receipt for
        // it; only the stkQuery verification was unavailable at that moment.
        // Moving it to Cancelled or Failed takes it out of the recovery sweep's
        // net -- which looks only at Pending and TimedOut -- and the money is
        // stranded for good.
        //
        // And this endpoint is UNAUTHENTICATED, so anyone holding the nonce
        // could post a 1032 to cause it deliberately.
        $this->provisionFloat();
        $user = $this->passenger();
        $attempt = $this->startedAttempt($user, 500, 'ws_CO_STRAND');

        // ONE fake with a SEQUENCE, because Http::fake() MERGES across calls:
        // a 500 registered first would shadow the success the sweep needs. The
        // first query 500s (Daraja unreachable at callback time), the second
        // succeeds (the sweep).
        Http::fake([
            '*oauth/v1/generate*' => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            '*stkpushquery/v1/query*' => Http::sequence()
                ->push([], 500)
                ->push(['ResultCode' => '0', 'MpesaReceiptNumber' => 'TGXSTRAND']),
        ]);

        // A success callback lands while Daraja cannot be reached, so the
        // receipt is kept but nothing is credited.
        $this->postJson('/api/wallet/top-ups/'.$attempt->callback_nonce.'/callback', [
            'Body' => ['stkCallback' => [
                'ResultCode' => 0,
                'CallbackMetadata' => ['Item' => [
                    ['Name' => 'Amount', 'Value' => 500],
                    ['Name' => 'MpesaReceiptNumber', 'Value' => 'TGXSTRAND'],
                ]],
            ]],
        ])->assertOk();

        $this->assertSame('TGXSTRAND', $attempt->refresh()->rail_receipt);
        $this->assertSame(WalletTopUpStatus::Pending, $attempt->status);

        // Now a cancellation arrives for the same attempt.
        $this->postJson('/api/wallet/top-ups/'.$attempt->callback_nonce.'/callback', [
            'Body' => ['stkCallback' => ['ResultCode' => 1032, 'ResultDesc' => 'Request cancelled by user']],
        ])->assertOk();

        // LEFT PENDING, not cancelled -- so it stays in the sweep's net. Pending
        // rather than timed_out because timed_out is terminal in the app and
        // clears the idempotency key; see the 1037 note in applyRailVerdict.
        $attempt->refresh();
        $this->assertSame(WalletTopUpStatus::Pending, $attempt->status);
        $this->assertTrue($attempt->status->mayStillSettle());

        // And the sweep still finds and credits it once Daraja answers (the
        // sequence's second response).
        $this->artisan('wallet:settle-top-ups')->assertSuccessful();

        $this->assertSame(50000, app(WalletService::class)->balanceCents((int) $user->id));
        $this->assertSame(WalletTopUpStatus::Completed, $attempt->refresh()->status);
    }

    #[Test]
    public function an_unreachable_handset_leaves_the_attempt_pending_not_failed(): void
    {
        // 1037 means nobody answered the prompt -- which says nothing about
        // where the money is. Written as timed_out it reads in the app as
        // 'Top-up did not go through' AND clears the idempotency key, so Try
        // again sends a second prompt; if the first payment then lands the
        // passenger is KES 1,000 down on a 500 top-up they were told failed.
        $this->provisionFloat();
        $this->fakeDarajaAccepts();
        $this->passenger();

        $this->postJson(self::TOP_UPS, ['amount' => 500, 'source' => 'mpesa'])->assertOk();
        $attempt = WalletTopUp::firstOrFail();

        $this->postJson('/api/wallet/top-ups/'.$attempt->callback_nonce.'/callback', [
            'Body' => ['stkCallback' => [
                'ResultCode' => 1037,
                'ResultDesc' => 'DS timeout user cannot be reached',
            ]],
        ])->assertOk();

        $attempt->refresh();
        $this->assertSame(WalletTopUpStatus::Pending, $attempt->status);
        $this->assertFalse($attempt->status->isFinal(), 'the app must keep waiting, not offer Try again');
        $this->assertNull($attempt->failure_reason);
    }

    #[Test]
    public function the_recovery_sweep_also_refuses_to_close_a_1037(): void
    {
        $this->provisionFloat();
        $this->fakeDarajaAccepts();
        $this->passenger();

        $this->postJson(self::TOP_UPS, ['amount' => 500, 'source' => 'mpesa'])->assertOk();
        $attempt = WalletTopUp::firstOrFail();

        $this->fakeDarajaDenies();
        $this->artisan('wallet:settle-top-ups')->assertSuccessful();

        $this->assertSame(WalletTopUpStatus::Pending, $attempt->refresh()->status);
    }

    #[Test]
    public function a_passenger_never_reads_safaricoms_own_wording(): void
    {
        // Every other refusal in the wallet goes through WalletRefusal. "DS
        // timeout user cannot be reached" under the heading 'Top-up did not go
        // through' is not copy.
        $this->provisionFloat();
        $this->fakeDarajaAccepts();
        $this->passenger();

        $this->postJson(self::TOP_UPS, ['amount' => 500, 'source' => 'mpesa'])->assertOk();
        $attempt = WalletTopUp::firstOrFail();

        $this->postJson('/api/wallet/top-ups/'.$attempt->callback_nonce.'/callback', [
            'Body' => ['stkCallback' => [
                'ResultCode' => 1032,
                'ResultDesc' => '[STKCallback] Request cancelled by user',
            ]],
        ])->assertOk();

        $attempt->refresh();
        $this->assertSame(WalletTopUpStatus::Cancelled, $attempt->status);
        $this->assertSame('You cancelled the M-Pesa prompt. No money left your account.', $attempt->failure_reason);
    }

    #[Test]
    public function a_daraja_error_envelope_does_not_close_a_pending_attempt(): void
    {
        // stkQuery returns $res->json() for a 4xx, so an expired token or a
        // throttle arrives as a perfectly good ARRAY carrying
        // {requestId, errorCode, errorMessage} and NO ResultCode. Defaulted to
        // Failed, that closed a row the sweep never looks at again -- and the
        // app reads failed as final and clears its idempotency key, so the
        // passenger is told no money left their M-Pesa and prompted again.
        //
        // Reachable only since 1037 stopped writing timed_out, which had been
        // shielding these rows from this branch by accident.
        $this->provisionFloat();
        $this->fakeDarajaAccepts();
        $this->passenger();

        $this->postJson(self::TOP_UPS, ['amount' => 500, 'source' => 'mpesa'])->assertOk();
        $attempt = WalletTopUp::firstOrFail();

        // A SEQUENCE, not two Http::fake() calls: fakes MERGE, and the first
        // callback registered for a pattern wins -- so a second fake for the
        // same URL is simply never reached.
        Http::fake([
            '*oauth/v1/generate*' => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            '*stkpushquery/v1/query*' => Http::sequence()
                ->push([
                    'requestId' => '11728-2929992-1',
                    'errorCode' => '404.001.03',
                    'errorMessage' => 'Invalid Access Token',
                ], 401)
                ->push(['ResultCode' => '1032', 'ResultDesc' => 'cancelled'], 200),
        ]);

        $this->artisan('wallet:settle-top-ups')->assertSuccessful();

        $attempt->refresh();
        $this->assertSame(WalletTopUpStatus::Pending, $attempt->status, 'a silence is never a refusal');
        $this->assertNull($attempt->failure_reason);

        // And a REAL refusal still closes it, so this is not just "never close".
        $this->artisan('wallet:settle-top-ups')->assertSuccessful();

        $this->assertSame(WalletTopUpStatus::Cancelled, $attempt->refresh()->status);
    }
}
