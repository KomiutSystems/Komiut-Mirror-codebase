<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use App\Enums\WalletEntryKind;
use App\Models\Mpesa;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletAccountNumber;
use App\Services\Wallet\WalletFloat;
use App\Services\Wallet\WalletService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Who is allowed to tell us that money arrived.
 *
 * THE HOLE THIS SUITE CLOSES. `/api/confirmation/{id}` is unauthenticated --
 * it has to be, Safaricom presents no credential, and 1.3M real confirmations
 * have been delivered to that URL shape. For a fare that is survivable: a
 * forged POST writes a visible, fixable `transactions` row. For a wallet it is
 * not, because the same forgery mints withdrawable balance out of nothing.
 *
 * And the thing deciding "this money landed in Komiut's account" was
 * `BusinessShortCode` -- A FIELD IN THE CALLER'S OWN REQUEST BODY. Every test
 * here is about the proof having moved out of the body and into a secret URL
 * only Safaricom is given.
 *
 * The gate was closed in practice only because the float shortcode was
 * unconfigured, which is the worst kind of safe: it would have opened at
 * exactly the moment somebody provisioned the paybill and stopped watching.
 */
final class WalletConfirmationTrustTest extends QueueTestCase
{
    private const SECRET = 'wlt_c2b_7f3a91c2d4e85b6079af1c2d3e4f5a6b';

    private const FLOAT_SHORTCODE = '400200';

    /** @return array{0: User, 1: string} */
    private function passengerWithAccount(): array
    {
        $user = $this->makeUser();
        $wallet = app(WalletService::class)->lock((int) $user->id);

        return [$user, WalletAccountNumber::forWallet($wallet)];
    }

    private function provisionFloat(bool $withSecret = true): void
    {
        config()->set('wallet.float.short_code', self::FLOAT_SHORTCODE);
        config()->set('wallet.float.consumer_key', 'ck');
        config()->set('wallet.float.consumer_secret', 'cs');
        config()->set('wallet.float.pass_key', 'pk');
        config()->set('wallet.float.confirmation_secret', $withSecret ? self::SECRET : null);
        config()->set('wallet.float.source_ips', []);
    }

    /** @return array<string, mixed> */
    private function confirmation(string $accountNo, string $amount = '500'): array
    {
        return [
            'TransID' => 'FORGE'.$this->nextSequence(),
            'TransAmount' => $amount,
            'TransTime' => now()->format('YmdHis'),
            'MSISDN' => '254712345678',
            'FirstName' => 'Attacker',
            // The field that used to be the whole gate.
            'BusinessShortCode' => self::FLOAT_SHORTCODE,
            'BillRefNumber' => $accountNo,
        ];
    }

    private function balance(User $user): int
    {
        return app(WalletService::class)->balanceCents((int) $user->id);
    }

    #[Test]
    public function the_generic_unauthenticated_receiver_cannot_credit_a_wallet(): void
    {
        // THE ATTACK. Anyone on the internet can POST this: no credential, and
        // every field including BusinessShortCode is theirs to choose. Before
        // the fix it credited KES 500 of balance that nobody ever paid.
        $this->provisionFloat();
        [$user, $accountNo] = $this->passengerWithAccount();

        $this->postJson('/api/confirmation/1', $this->confirmation($accountNo))->assertOk();

        $this->assertSame(0, $this->balance($user), 'a forged confirmation must mint nothing');
        $this->assertSame(0, WalletTransaction::where('kind', WalletEntryKind::TopUp->value)->count());
    }

    #[Test]
    public function the_float_secret_url_does_credit(): void
    {
        // The legitimate path: the URL we register with Safaricom for the float
        // shortcode. The secret in it is the proof the body cannot provide.
        $this->provisionFloat();
        [$user, $accountNo] = $this->passengerWithAccount();

        $this->postJson('/api/wallet/c2b/'.self::SECRET.'/confirmation', $this->confirmation($accountNo))
            ->assertOk();

        $this->assertSame(50000, $this->balance($user));
    }

    #[Test]
    public function a_wrong_secret_credits_nothing_and_still_answers_200(): void
    {
        // 200 because a non-2xx makes Safaricom retry, and a 500 on a payment
        // webhook is how the legacy backend lost 52 payments. A prober learns
        // nothing from the response; the rejection goes to the log.
        $this->provisionFloat();
        [$user, $accountNo] = $this->passengerWithAccount();

        $wrong = str_repeat('a', strlen(self::SECRET));

        $this->postJson('/api/wallet/c2b/'.$wrong.'/confirmation', $this->confirmation($accountNo))
            ->assertOk()
            ->assertSee('ResultCode', false);

        $this->assertSame(0, $this->balance($user));
    }

    #[Test]
    public function with_no_secret_configured_nothing_can_credit_at_all(): void
    {
        // The SHIPPED state, and deliberately the opposite default to
        // services.coop.source_ips (where unset means open): refusing a real
        // payment is recoverable by hand, minting balance from a forged one is
        // not.
        $this->provisionFloat(withSecret: false);
        [$user, $accountNo] = $this->passengerWithAccount();

        // Even guessing the route shape gets nowhere, because there is nothing
        // to match against.
        $this->postJson('/api/wallet/c2b/'.self::SECRET.'/confirmation', $this->confirmation($accountNo))
            ->assertOk();

        $this->assertSame(0, $this->balance($user));
        $this->assertFalse(app(WalletFloat::class)->confirmationsAreLive());
    }

    #[Test]
    public function an_address_outside_the_allowlist_credits_nothing(): void
    {
        // Defence in depth on top of the secret. An EMPTY list passes -- a wrong
        // address here would refuse real money and the secret is already
        // authenticating -- so this only bites once somebody sets it.
        $this->provisionFloat();
        config()->set('wallet.float.source_ips', ['196.201.214.0/24']);

        [$user, $accountNo] = $this->passengerWithAccount();

        $this->postJson('/api/wallet/c2b/'.self::SECRET.'/confirmation', $this->confirmation($accountNo))
            ->assertOk();

        $this->assertSame(0, $this->balance($user), 'the test client is not in Safaricom range');
    }

    #[Test]
    public function the_shortcode_check_still_applies_on_the_trusted_url(): void
    {
        // The body check is necessary and not sufficient: the secret proves WHO
        // is talking, the shortcode proves WHICH account the money reached. A
        // fare confirmation misdelivered to this URL must still not credit.
        $this->provisionFloat();
        [$user, $accountNo] = $this->passengerWithAccount();

        $fields = $this->confirmation($accountNo);
        $fields['BusinessShortCode'] = '174379'; // a SACCO till, not the float

        $this->postJson('/api/wallet/c2b/'.self::SECRET.'/confirmation', $fields)->assertOk();

        $this->assertSame(0, $this->balance($user));
    }

    #[Test]
    public function a_forged_confirmation_on_the_trusted_url_still_cannot_invent_a_wallet(): void
    {
        // Having the secret is not having a wallet: the account number still has
        // to resolve. A random one is money on our shortcode with nowhere to go,
        // which the admin console's unmatched screen is for.
        $this->provisionFloat();
        [$user] = $this->passengerWithAccount();

        $this->postJson('/api/wallet/c2b/'.self::SECRET.'/confirmation', $this->confirmation('WZZZZZZ'))
            ->assertOk();

        $this->assertSame(0, $this->balance($user));
        $this->assertSame(0, WalletTransaction::where('kind', WalletEntryKind::TopUp->value)->count());
    }

    #[Test]
    public function the_payment_is_still_recorded_even_when_it_credits_nothing(): void
    {
        // NOTHING HERE MAY COST US A PAYMENT. The whole reason the wallet hook
        // is wrapped and the ack is unconditional: a confirmation that cannot be
        // attributed must still be on record, or the money vanishes from our
        // side while it has already left the customer.
        $this->provisionFloat();
        [, $accountNo] = $this->passengerWithAccount();

        $fields = $this->confirmation($accountNo);
        $fields['BillRefNumber'] = 'WZZZZZZ';

        $this->postJson('/api/wallet/c2b/'.self::SECRET.'/confirmation', $fields)->assertOk();

        $this->assertNotNull(
            Mpesa::withoutGlobalScopes()->where('TransID', $fields['TransID'])->first(),
            'the payment is recorded even though no wallet matched',
        );
    }

    #[Test]
    public function a_replayed_trusted_confirmation_credits_once(): void
    {
        $this->provisionFloat();
        [$user, $accountNo] = $this->passengerWithAccount();

        $fields = $this->confirmation($accountNo);
        $url = '/api/wallet/c2b/'.self::SECRET.'/confirmation';

        $this->postJson($url, $fields)->assertOk();
        $this->postJson($url, $fields)->assertOk();

        $this->assertSame(50000, $this->balance($user));
        $this->assertSame(1, WalletTransaction::where('kind', WalletEntryKind::TopUp->value)->count());
    }

    #[Test]
    public function an_ordinary_fare_confirmation_is_untouched_by_any_of_this(): void
    {
        // The 45,000 real payments a day that have nothing to do with wallets
        // must keep working exactly as before, through the generic receiver.
        $this->provisionFloat();
        $world = $this->makeWorld();

        $fields = [
            'TransID' => 'FARE'.$this->nextSequence(),
            'TransAmount' => '70',
            'TransTime' => now()->format('YmdHis'),
            'MSISDN' => '254712345678',
            'FirstName' => 'Marylyne',
            'BusinessShortCode' => '174379',
            'BillRefNumber' => $world['vehicle']->plate,
        ];

        $this->postJson('/api/confirmation/1', $fields)->assertOk();

        $this->assertNotNull(Mpesa::withoutGlobalScopes()->where('TransID', $fields['TransID'])->first());
        $this->assertSame(0, Wallet::count(), 'and no wallet was involved');
    }
}
