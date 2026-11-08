<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use App\Enums\WalletEntryKind;
use App\Enums\WalletTopUpRail;
use App\Enums\WalletTopUpStatus;
use App\Models\Mpesa;
use App\Models\Wallet;
use App\Models\WalletTopUp;
use App\Models\WalletTransaction;
use App\Services\Mpesa\C2bPaymentRecorder;
use App\Services\Wallet\WalletAccountNumber;
use App\Services\Wallet\WalletService;
use App\Services\Wallet\WalletTopUpService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Money actually arriving — and, far more importantly, money NOT arriving.
 *
 * THE SECURITY PROPERTY THIS SUITE EXISTS FOR: every M-Pesa credential in this
 * system belongs to a SACCO (`mpesa_payment_settings`, resolved booking ->
 * vehicle -> SACCO; NCBA's 880100 tills are per-bus). None is Komiut's. So if a
 * wallet could be credited by a payment that landed on one of those, a
 * passenger could type their wallet account number into ANY bus's paybill and
 * be credited with that SACCO's collections — the platform would issue float
 * against somebody else's money.
 *
 * Hence `a_payment_on_a_saccos_till_never_credits_a_wallet`, which is the single
 * most important test here, and the fail-closed default that makes it hold
 * before anyone has configured anything.
 *
 * Covers WalletTopUpService::settleFromConfirmation, the hook in
 * C2bPaymentRecorder, and wallet:reconcile.
 */
final class WalletFloatIngestTest extends QueueTestCase
{
    private const FLOAT_SHORTCODE = '400200';

    private function provisionFloat(): void
    {
        config()->set('wallet.float.short_code', self::FLOAT_SHORTCODE);
        config()->set('wallet.float.consumer_key', 'ck');
        config()->set('wallet.float.consumer_secret', 'cs');
        config()->set('wallet.float.pass_key', 'pk');
    }

    private function service(): WalletTopUpService
    {
        return app(WalletTopUpService::class);
    }

    /** A passenger with a wallet and an account number ready to receive. */
    private function passengerWithAccount(): array
    {
        $user = $this->makeUser();
        $wallet = app(WalletService::class)->lock((int) $user->id);
        $accountNo = WalletAccountNumber::forWallet($wallet);

        return [$user, $wallet->refresh(), $accountNo];
    }

    /** @param array<string, mixed> $overrides */
    private function confirmation(string $accountNo, string $amount, array $overrides = []): array
    {
        return array_merge([
            'TransID' => 'WLT'.$this->nextSequence(),
            'TransAmount' => $amount,
            'TransTime' => now()->format('YmdHis'),
            'MSISDN' => '254712345678',
            'FirstName' => 'Marylyne',
            'BusinessShortCode' => self::FLOAT_SHORTCODE,
            'BillRefNumber' => $accountNo,
        ], $overrides);
    }

    private function mpesaRowFor(array $fields): Mpesa
    {
        return Mpesa::withoutGlobalScopes()->create([
            'TransID' => $fields['TransID'],
            'MSISDN' => $fields['MSISDN'],
            'TransAmount' => $fields['TransAmount'],
            'TransTime' => now(),
            'FirstName' => $fields['FirstName'],
            'BillRefNumber' => $fields['BillRefNumber'],
        ]);
    }

    #[Test]
    public function a_payment_on_the_float_shortcode_credits_the_wallet(): void
    {
        $this->provisionFloat();
        [$user, , $accountNo] = $this->passengerWithAccount();

        $fields = $this->confirmation($accountNo, '500');
        $entry = $this->service()->settleFromConfirmation($fields, $this->mpesaRowFor($fields));

        $this->assertNotNull($entry);
        $this->assertSame(WalletEntryKind::TopUp, $entry->kind);
        $this->assertSame(50000, (int) $entry->amount_cents);
        $this->assertSame(50000, (int) $entry->balance_after_cents);
        $this->assertSame(50000, app(WalletService::class)->balanceCents((int) $user->id));
        $this->assertStringContainsString('Marylyne', (string) $entry->detail);
    }

    #[Test]
    public function a_payment_on_a_saccos_till_never_credits_a_wallet(): void
    {
        // THE ONE THAT MATTERS. Without this check a passenger could type their
        // wallet number into any bus's paybill and be credited with that
        // SACCO's collections.
        $this->provisionFloat();
        [$user, , $accountNo] = $this->passengerWithAccount();

        $fields = $this->confirmation($accountNo, '500', ['BusinessShortCode' => '174379']);

        $this->assertNull($this->service()->settleFromConfirmation($fields, $this->mpesaRowFor($fields)));
        $this->assertSame(0, app(WalletService::class)->balanceCents((int) $user->id));
        $this->assertSame(0, WalletTransaction::count());
    }

    #[Test]
    public function with_no_float_configured_nothing_credits_at_all(): void
    {
        // Fail closed. This is the shipped state: WALLET_FLOAT_SHORT_CODE is
        // unset until somebody provisions a Komiut paybill.
        config()->set('wallet.float.short_code', null);
        [$user, , $accountNo] = $this->passengerWithAccount();

        $fields = $this->confirmation($accountNo, '500');

        $this->assertNull($this->service()->settleFromConfirmation($fields, $this->mpesaRowFor($fields)));
        $this->assertSame(0, app(WalletService::class)->balanceCents((int) $user->id));
    }

    #[Test]
    public function an_ordinary_fare_reference_is_not_mistaken_for_a_wallet(): void
    {
        // Every paybill payment carries a BillRefNumber. A plate, a till
        // number, a booking id must all fail to parse rather than be hunted
        // for in the wallets table.
        $this->provisionFloat();
        $this->passengerWithAccount();

        foreach (['KDL126J', '299106', '', 'WALLET'] as $ref) {
            $fields = $this->confirmation($ref, '500');
            $this->assertNull(
                $this->service()->settleFromConfirmation($fields, $this->mpesaRowFor($fields)),
                "[{$ref}] must not resolve to a wallet",
            );
        }

        $this->assertSame(0, WalletTransaction::count());
    }

    #[Test]
    public function the_amount_credited_is_the_amount_received(): void
    {
        // A passenger prompted for 500 who sends 400 gets 400. Crediting the
        // requested amount would put the ledger and the bank out of step by
        // the difference.
        $this->provisionFloat();
        [$user, $wallet, $accountNo] = $this->passengerWithAccount();

        WalletTopUp::create([
            'wallet_id' => $wallet->id,
            'amount_cents' => 50000,
            'rail' => WalletTopUpRail::Mpesa,
            'status' => WalletTopUpStatus::Pending,
            'idempotency_key' => 'asked-for-500',
        ]);

        $fields = $this->confirmation($accountNo, '400');
        $entry = $this->service()->settleFromConfirmation($fields, $this->mpesaRowFor($fields));

        $this->assertSame(40000, (int) $entry->amount_cents);
        $this->assertSame(40000, app(WalletService::class)->balanceCents((int) $user->id));

        $attempt = WalletTopUp::firstOrFail();
        $this->assertSame(WalletTopUpStatus::Completed, $attempt->status);
        $this->assertSame(40000, (int) $attempt->amount_cents, 'the attempt is restated to what was paid');
        $this->assertSame((int) $entry->id, (int) $attempt->wallet_transaction_id);
        $this->assertNotNull($attempt->completed_at);
    }

    #[Test]
    public function a_late_payment_still_settles_a_timed_out_attempt(): void
    {
        $this->provisionFloat();
        [, $wallet, $accountNo] = $this->passengerWithAccount();

        WalletTopUp::create([
            'wallet_id' => $wallet->id,
            'amount_cents' => 50000,
            'rail' => WalletTopUpRail::Mpesa,
            'status' => WalletTopUpStatus::TimedOut,
            'idempotency_key' => 'gave-up-waiting',
        ]);

        $fields = $this->confirmation($accountNo, '500');
        $this->service()->settleFromConfirmation($fields, $this->mpesaRowFor($fields));

        // Calling it timed out was a guess about the prompt. The money is not a
        // guess.
        $this->assertSame(WalletTopUpStatus::Completed, WalletTopUp::firstOrFail()->status);
        $this->assertSame(1, WalletTopUp::count(), 'it settled the existing attempt rather than inventing one');
    }

    #[Test]
    public function paying_the_paybill_by_hand_still_credits_and_is_recorded(): void
    {
        // No prompt, no attempt row -- somebody just typed the paybill and
        // their wallet number into M-Pesa. It has to work identically, and
        // appear in their top-up history.
        $this->provisionFloat();
        [$user, , $accountNo] = $this->passengerWithAccount();

        $fields = $this->confirmation($accountNo, '300');
        $entry = $this->service()->settleFromConfirmation($fields, $this->mpesaRowFor($fields));

        $this->assertSame(30000, app(WalletService::class)->balanceCents((int) $user->id));

        $attempt = WalletTopUp::firstOrFail();
        $this->assertSame(WalletTopUpStatus::Completed, $attempt->status);
        $this->assertSame((int) $entry->id, (int) $attempt->wallet_transaction_id);
        $this->assertSame('254712345678', $attempt->phone);
    }

    #[Test]
    public function a_replayed_confirmation_credits_once(): void
    {
        $this->provisionFloat();
        [$user, , $accountNo] = $this->passengerWithAccount();

        $fields = $this->confirmation($accountNo, '500');
        $mpesa = $this->mpesaRowFor($fields);

        $first = $this->service()->settleFromConfirmation($fields, $mpesa);
        $again = $this->service()->settleFromConfirmation($fields, $mpesa);

        $this->assertSame((int) $first->id, (int) $again->id);
        $this->assertSame(50000, app(WalletService::class)->balanceCents((int) $user->id));
        $this->assertSame(1, WalletTransaction::count());
        $this->assertSame(1, WalletTopUp::count());
    }

    #[Test]
    public function the_c2b_recorder_credits_the_wallet_as_part_of_recording_the_payment(): void
    {
        // Proves the HOOK fires, not just the service. C2bPaymentRecorder is
        // the save chain every confirmation entry point shares, so hooking
        // there covers NCBA's two REST variants and the Pull reconciler at once.
        $this->provisionFloat();
        [$user, , $accountNo] = $this->passengerWithAccount();

        $fields = $this->confirmation($accountNo, '750');

        $result = app(C2bPaymentRecorder::class)->record($fields, fn () => null);

        $this->assertTrue($result->ok, 'the payment itself is recorded');
        $this->assertSame(75000, app(WalletService::class)->balanceCents((int) $user->id));
    }

    #[Test]
    public function a_wallet_credit_failure_never_loses_the_payment(): void
    {
        // The class docblock on C2bPaymentRecorder exists because 52 payments
        // once vanished when a save threw after the money had moved. A wallet
        // credit is worth less than the record of the payment, so it is caught.
        $this->provisionFloat();
        [, , $accountNo] = $this->passengerWithAccount();

        // Force the credit to blow up by removing the table it writes to.
        DB::statement('ALTER TABLE wallet_transactions RENAME TO wallet_transactions_hidden');

        try {
            $fields = $this->confirmation($accountNo, '500');
            $result = app(C2bPaymentRecorder::class)->record($fields, fn () => null);

            $this->assertTrue($result->ok, 'the payment is still recorded');
            $this->assertNotNull(Mpesa::withoutGlobalScopes()->where('TransID', $fields['TransID'])->first());
        } finally {
            DB::statement('ALTER TABLE wallet_transactions_hidden RENAME TO wallet_transactions');
        }
    }

    #[Test]
    public function reconcile_passes_on_a_healthy_float(): void
    {
        $this->provisionFloat();
        [, , $accountNo] = $this->passengerWithAccount();

        foreach (['500', '250'] as $amount) {
            $fields = $this->confirmation($accountNo, $amount);
            $this->service()->settleFromConfirmation($fields, $this->mpesaRowFor($fields));
        }

        $this->artisan('wallet:reconcile')->assertExitCode(0);
    }

    #[Test]
    public function reconcile_catches_a_balance_that_does_not_match_its_ledger(): void
    {
        // The identity the whole wallet rests on. This is the command's reason
        // to exist: a discrepancy found the day it appears is a bug, found six
        // months later it is a reconstruction project.
        $this->provisionFloat();
        [, , $accountNo] = $this->passengerWithAccount();

        $fields = $this->confirmation($accountNo, '500');
        $this->service()->settleFromConfirmation($fields, $this->mpesaRowFor($fields));

        // Straight past the service, the way a bad manual UPDATE during an
        // incident would.
        DB::table('wallets')->update(['balance_cents' => 999999]);

        $this->artisan('wallet:reconcile')
            ->expectsOutputToContain('WALLET FLOAT DOES NOT RECONCILE')
            ->assertExitCode(1);
    }

    #[Test]
    public function reconcile_catches_a_top_up_with_no_payment_behind_it(): void
    {
        // A balance with no M-Pesa payment behind it is money the platform owes
        // a passenger but was never paid.
        [$user] = $this->passengerWithAccount();

        app(WalletService::class)->credit(
            (int) $user->id, 50000, WalletEntryKind::TopUp, 'invented', ['title' => 'Wallet top-up']
        );

        $this->artisan('wallet:reconcile')
            ->expectsOutputToContain('no M-Pesa payment behind it')
            ->assertExitCode(1);
    }

    #[Test]
    public function reconcile_catches_a_tampered_running_total(): void
    {
        $this->provisionFloat();
        [, , $accountNo] = $this->passengerWithAccount();

        $fields = $this->confirmation($accountNo, '500');
        $this->service()->settleFromConfirmation($fields, $this->mpesaRowFor($fields));

        DB::table('wallet_transactions')->update(['balance_after_cents' => 123]);

        $this->artisan('wallet:reconcile')->assertExitCode(1);
    }

    #[Test]
    public function reconcile_reports_the_float_per_bank(): void
    {
        // The float liability is a number somebody should be able to read off a
        // cron log. Wallets with no float bank cannot be settled to anyone, so
        // they are called out separately.
        $this->provisionFloat();
        [, $wallet, $accountNo] = $this->passengerWithAccount();

        $fields = $this->confirmation($accountNo, '500');
        $this->service()->settleFromConfirmation($fields, $this->mpesaRowFor($fields));

        $this->artisan('wallet:reconcile')
            ->expectsOutputToContain('Float held: KES 500')
            ->assertExitCode(0);

        Wallet::whereKey($wallet->id)->update(['float_bank' => null]);

        $this->artisan('wallet:reconcile')
            ->expectsOutputToContain('cannot be settled')
            ->assertExitCode(0);
    }

    #[Test]
    public function a_float_bank_is_recorded_so_the_money_can_be_settled_later(): void
    {
        // One balance spendable on any bus cuts across how the two banks
        // reconcile -- NICCO alone runs 126 NCBA-financed vehicles and 54
        // Co-op ones. Which bank holds a passenger's float cannot be
        // reconstructed after the fact, so it is stamped at creation.
        [, $wallet] = $this->passengerWithAccount();

        $this->assertSame('ncba', $wallet->float_bank, 'the komiut brand banks with NCBA');
    }
}
