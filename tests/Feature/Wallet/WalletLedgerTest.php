<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use App\Enums\WalletEntryKind;
use App\Enums\WalletStatus;
use App\Models\Mpesa;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletRefusal;
use App\Services\Wallet\WalletService;
use App\Support\Shillings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The wallet ledger: the arithmetic, the guards and the replays.
 *
 * This is the half of the wallet that holds real money, so the tests are
 * written against the properties that must never break rather than against the
 * happy path:
 *
 *   - a balance can never go negative, and the attempt is REFUSED, not clamped
 *   - the same reference can never move money twice, however many times it
 *     arrives
 *   - one `mpesas` row can never credit a wallet twice, which is the whole
 *     reason the two top-up rails can run side by side
 *   - the stored balance and the replayed ledger always agree
 *
 * Phase 1 exposes no endpoint that moves money; these exercise WalletService
 * directly, which is the point of building the primitives first.
 */
final class WalletLedgerTest extends QueueTestCase
{
    private function service(): WalletService
    {
        return app(WalletService::class);
    }

    /** A passenger, as passengers actually are: users.sacco_id NULL. */
    private function passenger(): int
    {
        $user = $this->makeUser();

        $this->assertNull($user->sacco_id, 'wallets are platform-wide; a passenger has no home SACCO');

        return (int) $user->id;
    }

    #[Test]
    public function a_first_credit_creates_the_wallet_and_the_ledger_row(): void
    {
        $userId = $this->passenger();

        $entry = $this->service()->credit($userId, Shillings::toCents(500), WalletEntryKind::TopUp, 'ref-first');

        $wallet = Wallet::where('user_id', $userId)->firstOrFail();

        $this->assertSame(50000, (int) $wallet->balance_cents, 'KES 500 is 50,000 cents');
        $this->assertSame(500, $wallet->balanceShillings());
        $this->assertSame(50000, (int) $entry->amount_cents, 'a credit is positive');
        $this->assertSame(50000, (int) $entry->balance_after_cents);
        $this->assertSame('credit', $entry->direction());
        $this->assertSame(WalletEntryKind::TopUp, $entry->kind);
    }

    #[Test]
    public function a_debit_is_negative_but_reads_as_a_positive_amount(): void
    {
        $userId = $this->passenger();
        $this->service()->credit($userId, Shillings::toCents(500), WalletEntryKind::TopUp, 'ref-in');

        $entry = $this->service()->debit($userId, Shillings::toCents(120), WalletEntryKind::Fare, 'ref-fare');

        $this->assertSame(-12000, (int) $entry->amount_cents, 'the sign is on the row');
        $this->assertSame('debit', $entry->direction());
        $this->assertSame(120, $entry->amountShillings(), 'but the wire shows a magnitude, never -120');
        $this->assertSame(38000, (int) $entry->balance_after_cents);
        $this->assertSame(380, $this->service()->balanceCents($userId) / 100);
    }

    #[Test]
    public function an_overdraw_is_refused_and_moves_nothing(): void
    {
        $userId = $this->passenger();
        $this->service()->credit($userId, Shillings::toCents(100), WalletEntryKind::TopUp, 'ref-in');

        try {
            $this->service()->debit($userId, Shillings::toCents(101), WalletEntryKind::Fare, 'ref-over');
            $this->fail('an overdraw must be refused');
        } catch (WalletRefusal $refusal) {
            $this->assertSame('insufficient_balance', $refusal->errorCode);
            // The app renders the balance back to the passenger, so it must be
            // the balance BEFORE the refused attempt, in shillings.
            $this->assertSame(100, $refusal->extra['balance']);
        }

        $this->assertSame(10000, $this->service()->balanceCents($userId), 'the balance is untouched');
        $this->assertSame(1, WalletTransaction::count(), 'and no ledger row was written for the refusal');
    }

    #[Test]
    public function the_database_itself_refuses_a_negative_balance(): void
    {
        // Belt AND braces: WalletService already refuses an overdraw under the
        // row lock. This asserts the CHECK constraint behind it, which is what
        // protects the float from a future caller that bypasses the service, or
        // from a manual UPDATE during an incident.
        $userId = $this->passenger();
        $this->service()->credit($userId, Shillings::toCents(10), WalletEntryKind::TopUp, 'ref-in');

        $this->expectException(QueryException::class);

        DB::table('wallets')->where('user_id', $userId)->update(['balance_cents' => -1]);
    }

    #[Test]
    public function the_same_reference_never_moves_money_twice(): void
    {
        $userId = $this->passenger();

        $first = $this->service()->credit($userId, Shillings::toCents(300), WalletEntryKind::TopUp, 'stk-abc-123');
        $again = $this->service()->credit($userId, Shillings::toCents(300), WalletEntryKind::TopUp, 'stk-abc-123');

        $this->assertSame((int) $first->id, (int) $again->id, 'the stored row is replayed, not a new one');
        $this->assertSame(30000, $this->service()->balanceCents($userId), 'credited once');
        $this->assertSame(1, WalletTransaction::count());
    }

    #[Test]
    public function a_reference_minted_for_another_passenger_is_refused_rather_than_replayed(): void
    {
        $alice = $this->passenger();
        $bob = $this->passenger();

        $this->service()->credit($alice, Shillings::toCents(100), WalletEntryKind::TopUp, 'shared-ref');

        // Returning Alice's row to Bob would hand one passenger another's
        // movement -- and on a debit it would report Bob's fare as already
        // paid. It has to fail instead.
        $this->expectException(InvalidArgumentException::class);

        $this->service()->credit($bob, Shillings::toCents(100), WalletEntryKind::TopUp, 'shared-ref');
    }

    #[Test]
    public function one_mpesa_payment_can_only_ever_credit_once(): void
    {
        // THE CROSS-RAIL GUARD. STK push and the C2B webhook can both fire for
        // the same payment with no shared lock between them. Both resolve to a
        // row in `mpesas`, so a unique mpesa_id is what makes the second one
        // harmless -- without this, every STK top-up would double when the
        // confirmation webhook also landed.
        $userId = $this->passenger();
        $mpesa = $this->makeMpesaRow();

        $this->service()->credit($userId, Shillings::toCents(200), WalletEntryKind::TopUp, 'rail-stk', [
            'mpesa_id' => $mpesa,
        ]);

        $this->expectException(QueryException::class);

        // A DIFFERENT reference -- the other rail would naturally mint its own,
        // so the reference guard above cannot catch this one.
        $this->service()->credit($userId, Shillings::toCents(200), WalletEntryKind::TopUp, 'rail-c2b', [
            'mpesa_id' => $mpesa,
        ]);
    }

    #[Test]
    public function a_credit_cannot_push_the_balance_over_the_held_maximum(): void
    {
        config()->set('wallet.limits.max_balance', 1000);

        $userId = $this->passenger();
        $this->service()->credit($userId, Shillings::toCents(900), WalletEntryKind::TopUp, 'ref-in');

        try {
            $this->service()->credit($userId, Shillings::toCents(200), WalletEntryKind::TopUp, 'ref-over');
            $this->fail('the max balance is a float-risk control, not a suggestion');
        } catch (WalletRefusal $refusal) {
            $this->assertSame('max_balance', $refusal->errorCode);
            $this->assertSame(1000, $refusal->extra['max_balance']);
        }

        $this->assertSame(90000, $this->service()->balanceCents($userId));
    }

    #[Test]
    public function a_frozen_wallet_refuses_to_pay_but_still_accepts_money(): void
    {
        $userId = $this->passenger();
        $this->service()->credit($userId, Shillings::toCents(500), WalletEntryKind::TopUp, 'ref-in');

        Wallet::where('user_id', $userId)->update(['status' => WalletStatus::Frozen->value]);

        try {
            $this->service()->debit($userId, Shillings::toCents(50), WalletEntryKind::Fare, 'ref-fare');
            $this->fail('a frozen wallet must not pay');
        } catch (WalletRefusal $refusal) {
            $this->assertSame('wallet_frozen', $refusal->errorCode);
        }

        // A refund owed, or a top-up already paid for, must still land --
        // refusing it would strand real money outside the ledger.
        $refund = $this->service()->credit($userId, Shillings::toCents(50), WalletEntryKind::Refund, 'ref-refund');

        $this->assertSame(55000, (int) $refund->balance_after_cents);
    }

    #[Test]
    public function a_zero_movement_is_rejected_outright(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service()->credit($this->passenger(), 0, WalletEntryKind::Adjustment, 'ref-zero');
    }

    #[Test]
    public function the_ledger_replays_to_the_stored_balance(): void
    {
        // The property that makes the float auditable: sum the ledger and it
        // must equal the wallet row. If these ever diverge the platform cannot
        // say what it owes, which is the one question a licence conversation
        // will ask.
        $userId = $this->passenger();
        $svc = $this->service();

        $svc->credit($userId, Shillings::toCents(1000), WalletEntryKind::TopUp, 'r1');
        $svc->debit($userId, Shillings::toCents(150), WalletEntryKind::Fare, 'r2');
        $svc->debit($userId, Shillings::toCents(80), WalletEntryKind::Fare, 'r3');
        $svc->credit($userId, Shillings::toCents(80), WalletEntryKind::Refund, 'r4');
        $svc->debit($userId, Shillings::toCents(200), WalletEntryKind::Fare, 'r5');

        $walletId = Wallet::where('user_id', $userId)->value('id');

        $replayed = (int) WalletTransaction::where('wallet_id', $walletId)->sum('amount_cents');
        $stored = $svc->balanceCents($userId);

        $this->assertSame($replayed, $stored, 'the ledger and the balance must never disagree');
        $this->assertSame(65000, $stored);

        // And every row's running total is the truth at that point, in order.
        $running = 0;
        foreach (WalletTransaction::where('wallet_id', $walletId)->orderBy('id')->get() as $row) {
            $running += (int) $row->amount_cents;
            $this->assertSame($running, (int) $row->balance_after_cents, "row {$row->reference} mis-states the balance after it");
        }
    }

    #[Test]
    public function shillings_never_round_up_on_the_way_out(): void
    {
        // A passenger shown a shilling they cannot spend gets a refusal they
        // cannot explain, so the boundary floors. 150.99 reads as 150.
        $this->assertSame(150, Shillings::toShillings(15099));
        $this->assertSame(0, Shillings::toShillings(99));
        $this->assertSame(50000, Shillings::toCents(500));
    }

    #[Test]
    public function a_fractional_shilling_is_rejected_rather_than_truncated(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Shillings::toCents(10.5);
    }

    /** A minimal `mpesas` row, just enough to hang a wallet credit off. */
    private function makeMpesaRow(): int
    {
        return (int) Mpesa::withoutGlobalScopes()->create([
            'TransID' => 'WLT'.$this->nextSequence(),
            'MSISDN' => '254700000000',
            'TransAmount' => '200',
            'TransTime' => now(),
            'FirstName' => 'Ivy',
        ])->id;
    }
}
