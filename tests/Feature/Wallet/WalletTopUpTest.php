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
        $this->withHeaders(['Idempotency-Key' => 'shared'])
            ->postJson(self::TOP_UPS, ['amount' => 500, 'source' => 'mpesa'])->assertOk();

        $bob = $this->makeUser();
        Sanctum::actingAs($bob);

        // Returning Alice's attempt would show Bob her top-up and its amount.
        $this->withHeaders(['Idempotency-Key' => 'shared'])
            ->postJson(self::TOP_UPS, ['amount' => 500, 'source' => 'mpesa'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_request');
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
        $this->assertSame('Request cancelled by user', $attempt->failure_reason);
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

        $this->artisan('wallet:expire-top-ups')->assertSuccessful();

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
}
