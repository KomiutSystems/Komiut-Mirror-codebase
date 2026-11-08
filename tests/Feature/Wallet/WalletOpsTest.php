<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use App\Enums\Financier;
use App\Enums\WalletEntryKind;
use App\Enums\WalletSettlementStatus;
use App\Enums\WalletStatus;
use App\Models\AuditLog;
use App\Models\Mpesa;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletSettlement;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletOpsService;
use App\Services\Wallet\WalletPaymentRefused;
use App\Services\Wallet\WalletRefusal;
use App\Services\Wallet\WalletService;
use App\Services\Wallet\WalletSettlementService;
use App\Support\BusinessDay;
use App\Support\Shillings;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The operator's hands on a passenger's wallet.
 *
 * THESE ARE THE ONLY PATHS BY WHICH SOMEONE WHO IS NOT THE PASSENGER MOVES THE
 * PASSENGER'S MONEY, so the tests are mostly about the two things that make
 * that survivable: a mandatory reason, and an audit row naming who did it.
 *
 * The hardest case is a refund, because a wallet fare is accrued into a
 * settlement and then paid out to a SACCO. Refunding the passenger therefore
 * has three different consequences depending on how far that got, and getting
 * it wrong means either paying a SACCO for a refunded ride or quietly clawing
 * back money they were owed.
 */
final class WalletOpsTest extends QueueTestCase
{
    private function ops(): WalletOpsService
    {
        return app(WalletOpsService::class);
    }

    private function fundedPassenger(int $shillings = 1000): User
    {
        $user = $this->makeUser();

        $mpesa = Mpesa::withoutGlobalScopes()->create([
            'TransID' => 'OPS'.$this->nextSequence(),
            'MSISDN' => '254700000000',
            'TransAmount' => (string) $shillings,
            'TransTime' => Carbon::now(),
            'FirstName' => 'Seed',
        ]);

        app(WalletService::class)->credit(
            (int) $user->id,
            Shillings::toCents($shillings),
            WalletEntryKind::TopUp,
            'seed:'.$user->id,
            ['mpesa_id' => $mpesa->id],
        );

        return $user;
    }

    private function fare(User $user, int $saccoId, int $shillings, ?Carbon $at = null): WalletTransaction
    {
        $entry = app(WalletService::class)->debit(
            (int) $user->id,
            Shillings::toCents($shillings),
            WalletEntryKind::Fare,
            'fare:ops:'.$this->nextSequence(),
            ['title' => 'Fare', 'sacco_id' => $saccoId, 'financier' => Financier::Ncba->value],
        );

        if ($at !== null) {
            $entry->forceFill(['created_at' => $at])->save();
        }

        return $entry->refresh();
    }

    private function closedDay(): Carbon
    {
        [$start] = BusinessDay::windowFor(Carbon::now()->subDays(2));

        return $start->copy()->addHours(6);
    }

    private function balance(User $user): int
    {
        return app(WalletService::class)->balanceCents((int) $user->id);
    }

    // --------------------------------------------------------------- freezing

    #[Test]
    public function a_frozen_wallet_cannot_pay_but_can_still_receive(): void
    {
        // A hold stops a passenger spending. It must NOT stop a top-up they
        // have already paid for, or a refund they are owed, from landing --
        // refusing those would strand real money outside the ledger with
        // nothing to reconcile it against.
        $world = $this->makeWorld();
        $user = $this->fundedPassenger();

        $this->ops()->freeze((int) $user->id, 'Suspected card testing', 1);

        $wallet = Wallet::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(WalletStatus::Frozen, $wallet->status);
        $this->assertNotNull($wallet->frozen_at);
        $this->assertSame('Suspected card testing', $wallet->frozen_reason);

        // Paying is refused.
        try {
            $this->fare($user, (int) $world['sacco']->id, 100);
            $this->fail('a frozen wallet must not pay');
        } catch (WalletRefusal $refusal) {
            $this->assertSame('wallet_frozen', $refusal->errorCode);
        }

        // Receiving still works.
        $refund = app(WalletService::class)->credit(
            (int) $user->id, 5000, WalletEntryKind::Refund, 'owed:'.$user->id
        );
        $this->assertSame(105000, (int) $refund->balance_after_cents);
    }

    #[Test]
    public function freezing_and_unfreezing_are_both_audited(): void
    {
        $user = $this->fundedPassenger();

        $this->ops()->freeze((int) $user->id, 'Chargeback dispute', 42);
        $this->ops()->unfreeze((int) $user->id, 'Dispute resolved in their favour', 42);

        $this->assertSame(WalletStatus::Active, Wallet::where('user_id', $user->id)->firstOrFail()->status);

        // The audit trail is the whole point: who did it, when, and why.
        $frozen = AuditLog::where('action', 'wallet.frozen')->firstOrFail();
        $this->assertSame('Chargeback dispute', $frozen->data['reason']);
        $this->assertSame(1000, $frozen->data['balance'], 'the balance at the moment of the hold');
        $this->assertSame('42', (string) $frozen->actor_id);

        $unfrozen = AuditLog::where('action', 'wallet.unfrozen')->firstOrFail();
        $this->assertSame('Dispute resolved in their favour', $unfrozen->data['reason']);
    }

    #[Test]
    public function an_action_without_a_reason_is_refused(): void
    {
        // An unexplained movement of somebody else's money is the thing an audit
        // trail exists to make impossible, so this refuses rather than
        // defaulting to an empty string.
        $user = $this->fundedPassenger();

        foreach (['', '   ', 'ok'] as $tooThin) {
            try {
                $this->ops()->freeze((int) $user->id, $tooThin, 1);
                $this->fail("[{$tooThin}] should not be an acceptable reason");
            } catch (WalletPaymentRefused $refused) {
                $this->assertStringContainsString('Record why', $refused->getMessage());
            }
        }

        $this->assertSame(0, AuditLog::where('action', 'wallet.frozen')->count());
    }

    // ---------------------------------------------------------------- refunds

    #[Test]
    public function refunding_an_unaccrued_fare_just_gives_the_money_back(): void
    {
        $world = $this->makeWorld();
        $user = $this->fundedPassenger();
        $fare = $this->fare($user, (int) $world['sacco']->id, 150);

        $result = $this->ops()->refundFare((int) $fare->id, 'Bus never came', 7);

        $this->assertFalse($result['needs_clawback']);
        $this->assertSame(100000, $this->balance($user), 'back to where they started');
        $this->assertSame(WalletEntryKind::Refund, $result['entry']->kind);
        $this->assertSame(0, WalletSettlement::count(), 'and nothing was ever owed for it');
    }

    #[Test]
    public function refunding_an_accrued_but_unpaid_fare_takes_it_out_of_the_settlement(): void
    {
        // Otherwise we pay a SACCO for a ride that was refunded. The settlement
        // is reduced and the fare detached, so the accrual cannot claim it again
        // either.
        $world = $this->makeWorld();
        $user = $this->fundedPassenger();

        $keep = $this->fare($user, (int) $world['sacco']->id, 150, $this->closedDay());
        $refundMe = $this->fare($user, (int) $world['sacco']->id, 50, $this->closedDay());

        app(WalletSettlementService::class)->accrue();

        $settlement = WalletSettlement::firstOrFail();
        $this->assertSame(20000, (int) $settlement->gross_cents);
        $this->assertSame(2, (int) $settlement->fare_count);

        $result = $this->ops()->refundFare((int) $refundMe->id, 'Charged twice', 7);

        $this->assertFalse($result['needs_clawback']);

        $settlement->refresh();
        $this->assertSame(15000, (int) $settlement->gross_cents, 'reduced by the refunded fare');
        $this->assertSame(1, (int) $settlement->fare_count);
        // An approved figure that has since changed has not been agreed by
        // anyone, so it goes back to pending.
        $this->assertSame(WalletSettlementStatus::Pending, $settlement->status);

        $this->assertNull($refundMe->refresh()->wallet_settlement_id, 'detached');
        $this->assertSame((int) $settlement->id, (int) $keep->refresh()->wallet_settlement_id, 'the other is untouched');
    }

    #[Test]
    public function a_settlement_emptied_by_refunds_is_deleted_rather_than_zeroed(): void
    {
        // gross_cents carries a CHECK (> 0), and a settlement for nothing would
        // sit in the payout queue inviting somebody to approve it.
        $world = $this->makeWorld();
        $user = $this->fundedPassenger();
        $fare = $this->fare($user, (int) $world['sacco']->id, 150, $this->closedDay());

        app(WalletSettlementService::class)->accrue();
        $this->assertSame(1, WalletSettlement::count());

        $this->ops()->refundFare((int) $fare->id, 'Trip cancelled', 7);

        $this->assertSame(0, WalletSettlement::count());
    }

    #[Test]
    public function refunding_a_fare_the_sacco_was_already_paid_for_flags_a_clawback(): void
    {
        // THE CASE THAT COSTS MONEY. The SACCO has the cash. A paid settlement
        // is immutable, so nothing is adjusted -- but the passenger is still
        // refunded, because they are not the ones who should wait on a
        // reconciliation. The audit row has to say so loudly enough that
        // somebody recovers it.
        $world = $this->makeWorld();
        $user = $this->fundedPassenger();
        $fare = $this->fare($user, (int) $world['sacco']->id, 150, $this->closedDay());

        $settlements = app(WalletSettlementService::class);
        $settlements->accrue();
        $settlement = WalletSettlement::firstOrFail();
        $settlements->approve($settlement, 1);
        $settlements->markPaid($settlement, 'BANKREF9', 'manual', 1);

        $result = $this->ops()->refundFare((int) $fare->id, 'Passenger never travelled', 7);

        $this->assertTrue($result['needs_clawback']);
        $this->assertSame(100000, $this->balance($user), 'the passenger is made whole regardless');

        $settlement->refresh();
        $this->assertSame(WalletSettlementStatus::Paid, $settlement->status, 'a paid settlement is immutable');
        $this->assertSame(15000, (int) $settlement->gross_cents, 'and its figure is not rewritten');
        $this->assertSame((int) $settlement->id, (int) $fare->refresh()->wallet_settlement_id, 'still attached');

        // Its OWN audit event, so it can be alerted on rather than found.
        $log = AuditLog::where('action', 'wallet.refund.after_settlement')->firstOrFail();
        $this->assertTrue($log->data['needs_clawback']);
        $this->assertStringContainsString('Recover it manually', (string) $log->data['note']);
    }

    #[Test]
    public function refunding_the_same_fare_twice_pays_back_once(): void
    {
        $world = $this->makeWorld();
        $user = $this->fundedPassenger();
        $fare = $this->fare($user, (int) $world['sacco']->id, 150);

        $first = $this->ops()->refundFare((int) $fare->id, 'Bus never came', 7);
        $again = $this->ops()->refundFare((int) $fare->id, 'Bus never came', 7);

        $this->assertSame((int) $first['entry']->id, (int) $again['entry']->id, 'the stored credit is replayed');
        $this->assertSame(100000, $this->balance($user));
        $this->assertSame(1, WalletTransaction::where('kind', WalletEntryKind::Refund->value)->count());
    }

    #[Test]
    public function only_a_fare_can_be_refunded(): void
    {
        $user = $this->fundedPassenger();
        $topUp = WalletTransaction::where('kind', WalletEntryKind::TopUp->value)->firstOrFail();

        $this->expectException(WalletPaymentRefused::class);

        $this->ops()->refundFare((int) $topUp->id, 'Wrong button', 7);
    }

    // ------------------------------------------------------------ adjustments

    #[Test]
    public function an_adjustment_moves_the_balance_and_says_who_did_it(): void
    {
        $user = $this->fundedPassenger();

        $credit = $this->ops()->adjust((int) $user->id, 250, 'Goodwill after the 7 Nov outage', 42);

        $this->assertSame(WalletEntryKind::Adjustment, $credit->kind);
        $this->assertSame(125000, $this->balance($user));
        $this->assertSame(42, (int) $credit->actor_id);

        $debit = $this->ops()->adjust((int) $user->id, -50, 'Reversing a duplicate goodwill credit', 42);

        $this->assertSame('debit', $debit->direction());
        $this->assertSame(120000, $this->balance($user));

        $log = AuditLog::where('action', 'wallet.adjusted')->orderBy('id')->first();
        $this->assertSame(250, $log->data['amount']);
        $this->assertSame('Goodwill after the 7 Nov outage', $log->data['reason']);
    }

    #[Test]
    public function an_adjustment_cannot_push_a_balance_negative(): void
    {
        // It goes through the ordinary ledger, so it obeys the same balance
        // constraint a passenger's own fare does. An operator mistake cannot
        // produce money the platform would have to invent.
        $user = $this->fundedPassenger(100);

        try {
            $this->ops()->adjust((int) $user->id, -500, 'Fat-fingered correction', 42);
            $this->fail('an adjustment must not overdraw');
        } catch (WalletRefusal $refusal) {
            $this->assertSame('insufficient_balance', $refusal->errorCode);
        }

        $this->assertSame(10000, $this->balance($user), 'untouched');
    }

    #[Test]
    public function an_adjustment_of_nothing_is_refused(): void
    {
        $user = $this->fundedPassenger();

        $this->expectException(WalletPaymentRefused::class);

        $this->ops()->adjust((int) $user->id, 0, 'Nothing to see here', 42);
    }

    #[Test]
    public function two_identical_adjustments_are_two_events_not_a_replay(): void
    {
        // Unlike a fare or a top-up, two corrections of the same amount for the
        // same passenger are genuinely two different decisions. A shared
        // reference would silently swallow the second.
        $user = $this->fundedPassenger();

        $this->ops()->adjust((int) $user->id, 100, 'Goodwill, first instalment', 42);
        $this->ops()->adjust((int) $user->id, 100, 'Goodwill, second instalment', 42);

        $this->assertSame(120000, $this->balance($user));
        $this->assertSame(2, WalletTransaction::where('kind', WalletEntryKind::Adjustment->value)->count());
    }

    #[Test]
    public function every_adjustment_reconciles(): void
    {
        // The point of routing operator corrections through the ledger: the
        // float still adds up afterwards.
        $user = $this->fundedPassenger();

        $this->ops()->adjust((int) $user->id, 250, 'Goodwill after an outage', 42);
        $this->ops()->adjust((int) $user->id, -100, 'Partial reversal', 42);

        $this->artisan('wallet:reconcile')->assertExitCode(0);
    }
}
