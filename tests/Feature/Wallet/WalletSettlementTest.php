<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use App\Enums\Financier;
use App\Enums\WalletEntryKind;
use App\Enums\WalletSettlementStatus;
use App\Models\Mpesa;
use App\Models\User;
use App\Models\WalletSettlement;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletPaymentRefused;
use App\Services\Wallet\WalletService;
use App\Services\Wallet\WalletSettlementService;
use App\Support\BusinessDay;
use App\Support\Shillings;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * What Komiut owes the SACCOs, and paying it.
 *
 * THE LIABILITY THESE TESTS PROTECT. A wallet fare takes a passenger's money
 * and gives a SACCO's bus the ride. Until it is accrued, that obligation exists
 * nowhere a person can see; until it is paid, it is money Komiut is sitting on.
 * The two failure modes are symmetrical and both unacceptable: never paying a
 * SACCO for rides they carried, and paying them twice out of a float that
 * belongs to passengers.
 *
 * So most of this is about the settle-once guarantee and the one-way ratchet on
 * status, not about the happy path.
 */
final class WalletSettlementTest extends QueueTestCase
{
    private function service(): WalletSettlementService
    {
        return app(WalletSettlementService::class);
    }

    /**
     * A fare, dated into a CLOSED business day so the accrual will look at it.
     *
     * Written straight through WalletService rather than through the payment
     * endpoint: this suite is about the accrual, and driving it through a live
     * booking would make every test depend on the whole spend path too.
     */
    private function fare(User $user, int $saccoId, ?string $financier, int $shillings, Carbon $at): WalletTransaction
    {
        $entry = app(WalletService::class)->debit(
            (int) $user->id,
            Shillings::toCents($shillings),
            WalletEntryKind::Fare,
            'fare:test:'.$this->nextSequence(),
            ['title' => 'Fare', 'sacco_id' => $saccoId, 'financier' => $financier],
        );

        // forceFill so the timestamp is exactly the business day under test;
        // created_at is what the accrual windows on.
        $entry->forceFill(['created_at' => $at])->save();

        return $entry->refresh();
    }

    /** Under wallet.limits.max_balance (50,000) -- a bigger seed is itself refused. */
    private function fundedPassenger(int $shillings = 1000): User
    {
        $user = $this->makeUser();

        // Seeded through a REAL mpesas row, because wallet:reconcile check 4
        // asserts every top-up traces to a payment -- a bare credit would make
        // every reconcile assertion in this suite fail for the wrong reason.
        $mpesa = Mpesa::withoutGlobalScopes()->create([
            'TransID' => 'SEED'.$this->nextSequence(),
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

    /** The 03:00-EAT start of a business day that has definitely closed. */
    private function closedDay(int $daysAgo = 2): Carbon
    {
        [$start] = BusinessDay::windowFor(Carbon::now()->subDays($daysAgo));

        return $start->copy()->addHours(6);
    }

    #[Test]
    public function fares_accrue_into_one_settlement_per_sacco_per_day(): void
    {
        $world = $this->makeWorld();
        $user = $this->fundedPassenger();
        $at = $this->closedDay();

        $this->fare($user, (int) $world['sacco']->id, Financier::Ncba->value, 150, $at);
        $this->fare($user, (int) $world['sacco']->id, Financier::Ncba->value, 50, $at->copy()->addHour());

        $result = $this->service()->accrue();

        $this->assertSame(1, $result['settlements']);
        $this->assertSame(2, $result['fares']);

        $settlement = WalletSettlement::firstOrFail();
        $this->assertSame(20000, (int) $settlement->gross_cents, 'the two fares, summed');
        $this->assertSame(200, $settlement->grossShillings());
        $this->assertSame(WalletSettlementStatus::Pending, $settlement->status);
        $this->assertSame('NCBA', $settlement->financier);

        // Every fare now names its settlement, which is what makes it
        // unclaimable by a second run.
        $this->assertSame(2, WalletTransaction::where('wallet_settlement_id', $settlement->id)->count());
    }

    #[Test]
    public function the_two_banks_are_settled_separately(): void
    {
        // NICCO MOVERS alone runs 126 NCBA-financed vehicles and 54 Co-op ones,
        // and per App\Enums\Financier the two banks reconcile against it
        // separately. One settlement per SACCO per day would mix their money
        // into a single payout neither could check.
        $world = $this->makeWorld();
        $user = $this->fundedPassenger();
        $at = $this->closedDay();

        $this->fare($user, (int) $world['sacco']->id, Financier::Ncba->value, 150, $at);
        $this->fare($user, (int) $world['sacco']->id, Financier::Coop->value, 80, $at);
        // A bus no bank finances still earned its fare and still gets paid.
        $this->fare($user, (int) $world['sacco']->id, null, 60, $at);

        $this->service()->accrue();

        $this->assertSame(3, WalletSettlement::count(), 'one per bank, including none');

        $byBank = WalletSettlement::get()->keyBy(fn (WalletSettlement $s) => $s->financier ?? 'none');

        $this->assertSame(15000, (int) $byBank['NCBA']->gross_cents);
        $this->assertSame(8000, (int) $byBank['coop-bank']->gross_cents);
        $this->assertSame(6000, (int) $byBank['none']->gross_cents);
        $this->assertSame('No bank', $byBank['none']->bankLabel());
    }

    #[Test]
    public function a_fare_is_never_accrued_twice(): void
    {
        // THE SETTLE-ONCE GUARANTEE. The accrual runs on a schedule and is
        // re-runnable by hand after a failure, so a second pass over the same
        // day must find nothing left to claim -- otherwise every manual retry
        // would double what a SACCO is owed.
        $world = $this->makeWorld();
        $user = $this->fundedPassenger();

        $this->fare($user, (int) $world['sacco']->id, Financier::Ncba->value, 150, $this->closedDay());

        $first = $this->service()->accrue();
        $second = $this->service()->accrue();

        $this->assertSame(1, $first['settlements']);
        $this->assertSame(0, $second['settlements'], 'nothing left to claim');
        $this->assertSame(1, WalletSettlement::count());
        $this->assertSame(15000, (int) WalletSettlement::firstOrFail()->gross_cents);
    }

    #[Test]
    public function todays_fares_are_not_accrued_yet(): void
    {
        // A business day still running would accrue a partial figure, get
        // approved and paid, and then take more fares with no row left to claim
        // them -- they would sit unsettled forever behind a paid period.
        $world = $this->makeWorld();
        $user = $this->fundedPassenger();

        $this->fare($user, (int) $world['sacco']->id, Financier::Ncba->value, 150, Carbon::now());

        $this->assertSame(0, $this->service()->accrue()['settlements']);
        $this->assertSame(0, WalletSettlement::count());

        // But it IS counted as owed -- just not yet accrued.
        $this->assertSame(15000, $this->service()->unaccruedCents());
    }

    #[Test]
    public function a_settlement_must_be_approved_before_it_can_be_paid(): void
    {
        $settlement = $this->accruedSettlement();

        try {
            $this->service()->markPaid($settlement, 'BANKREF1', 'manual', 1);
            $this->fail('a pending settlement must not be payable');
        } catch (WalletPaymentRefused $refused) {
            $this->assertStringContainsString('Approve this settlement', $refused->getMessage());
        }

        $this->service()->approve($settlement, 7);
        $settlement->refresh();

        $this->assertSame(WalletSettlementStatus::Approved, $settlement->status);
        $this->assertSame(7, (int) $settlement->approved_by);
        $this->assertNotNull($settlement->approved_at);

        $this->service()->markPaid($settlement, 'BANKREF1', 'manual', 7);

        $this->assertSame(WalletSettlementStatus::Paid, $settlement->refresh()->status);
        $this->assertSame('BANKREF1', $settlement->payout_reference);
        $this->assertSame('manual', $settlement->payout_rail);
    }

    #[Test]
    public function paying_without_a_reference_is_refused(): void
    {
        // "Paid" with nothing to point at is an assertion nobody can check, and
        // this row is the only record a SACCO was settled for that day.
        $settlement = $this->accruedSettlement();
        $this->service()->approve($settlement, 1);

        $this->expectException(WalletPaymentRefused::class);

        $this->service()->markPaid($settlement, '   ', 'manual', 1);
    }

    #[Test]
    public function a_paid_settlement_cannot_be_paid_again(): void
    {
        // The worse of the two failure modes: paying twice out of a float that
        // is passengers' money.
        $settlement = $this->accruedSettlement();
        $this->service()->approve($settlement, 1);
        $this->service()->markPaid($settlement, 'BANKREF1', 'manual', 1);

        // The SAME reference is a retried click, not a second payment.
        $again = $this->service()->markPaid($settlement, 'BANKREF1', 'manual', 1);
        $this->assertSame('BANKREF1', $again->payout_reference);

        // A DIFFERENT one means somebody is about to record a second payment
        // for one debt.
        try {
            $this->service()->markPaid($settlement, 'BANKREF2', 'manual', 1);
            $this->fail('a second payout reference must be refused');
        } catch (WalletPaymentRefused $refused) {
            $this->assertStringContainsString('already paid under reference BANKREF1', $refused->getMessage());
        }
    }

    #[Test]
    public function approving_an_already_paid_settlement_is_refused(): void
    {
        $settlement = $this->accruedSettlement();
        $this->service()->approve($settlement, 1);
        $this->service()->markPaid($settlement, 'BANKREF1', 'manual', 1);

        $this->expectException(WalletPaymentRefused::class);

        $this->service()->approve($settlement, 1);
    }

    #[Test]
    public function the_reference_is_derived_so_a_re_accrual_cannot_double_the_debt(): void
    {
        // Derived rather than random: if a run is rolled back and re-attempted,
        // the same period produces the same key and collides on the unique
        // index instead of minting a second payable row for one debt.
        $world = $this->makeWorld();
        $user = $this->fundedPassenger();
        $at = $this->closedDay();

        $this->fare($user, (int) $world['sacco']->id, Financier::Ncba->value, 150, $at);
        $this->service()->accrue();

        $reference = WalletSettlement::firstOrFail()->reference;

        [$dayStart] = BusinessDay::windowFor($at);
        $this->assertSame(
            'WS-'.$dayStart->format('Ymd').'-'.$world['sacco']->id.'-ncba',
            $reference,
        );
    }

    #[Test]
    public function reconcile_counts_only_paid_settlements_as_settled(): void
    {
        // An approved settlement is a promise, not a payment. Counting it would
        // make the float look lighter than it is.
        $settlement = $this->accruedSettlement();

        $this->assertSame(0, $this->service()->settledCents());

        $this->service()->approve($settlement, 1);
        $this->assertSame(0, $this->service()->settledCents(), 'approved is not paid');

        $this->service()->markPaid($settlement, 'BANKREF1', 'manual', 1);
        $this->assertSame(15000, $this->service()->settledCents());
    }

    #[Test]
    public function reconcile_catches_closed_days_the_accrual_never_swept(): void
    {
        // A fare that is spent but belongs to no settlement is money a SACCO has
        // earned and nobody has recorded owing them. Nothing else notices: the
        // float identity still balances, because unaccrued fares are simply
        // absent from `settled` on both sides.
        $world = $this->makeWorld();
        $user = $this->fundedPassenger();

        $this->fare($user, (int) $world['sacco']->id, Financier::Ncba->value, 150, $this->closedDay());

        $this->artisan('wallet:reconcile')
            ->expectsOutputToContain('have no settlement -- the accrual has not run')
            ->assertExitCode(1);

        $this->service()->accrue();
        $this->artisan('wallet:reconcile')->assertExitCode(0);
    }

    #[Test]
    public function the_ledger_identity_survives_a_payout(): void
    {
        // THE BUG THIS TEST FOUND. Check 8 originally read
        // `received + returned - spent - settled == held`, which looks right and
        // is not: settling pays a SACCO out of KOMIUT'S bank, never out of a
        // passenger's balance -- the passenger already lost the money when the
        // fare was debited. Subtracting `settled` counted the same shillings
        // twice, so the check failed on every paid settlement.
        //
        // received 1000 - spent 150 = 850, which is what the passenger holds,
        // whether or not the SACCO has been paid.
        $settlement = $this->accruedSettlement();
        $this->service()->approve($settlement, 1);
        $this->service()->markPaid($settlement, 'BANKREF1', 'manual', 1);

        $this->artisan('wallet:reconcile')->assertExitCode(0);
    }

    #[Test]
    public function reconcile_catches_paying_out_more_than_passengers_spent(): void
    {
        // Check 11, the other half of the corrected identity. If `settled` ever
        // exceeds `spent` then a settlement was accrued from something that was
        // not a fare, or paid twice, or a refunded ride was still paid for --
        // money gone that no query can recover.
        $settlement = $this->accruedSettlement();
        $this->service()->approve($settlement, 1);
        $this->service()->markPaid($settlement, 'BANKREF1', 'manual', 1);

        // Straight past the service, the way a bad manual UPDATE would.
        WalletSettlement::whereKey($settlement->id)->update(['gross_cents' => 999999]);

        $this->artisan('wallet:reconcile')
            ->expectsOutputToContain('exceeds what passengers spent')
            ->assertExitCode(1);
    }

    /** One accrued, pending settlement worth KES 150. */
    private function accruedSettlement(): WalletSettlement
    {
        $world = $this->makeWorld();
        $user = $this->fundedPassenger();

        $this->fare($user, (int) $world['sacco']->id, Financier::Ncba->value, 150, $this->closedDay());
        $this->service()->accrue();

        return WalletSettlement::firstOrFail();
    }
}
