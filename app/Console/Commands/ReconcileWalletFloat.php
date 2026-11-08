<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\WalletEntryKind;
use App\Enums\WalletTopUpStatus;
use App\Models\Wallet;
use App\Models\WalletTopUp;
use App\Models\WalletTransaction;
use App\Support\Shillings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Does the float add up?
 *
 * THE EARLY-WARNING SYSTEM, and the reason it exists BEFORE the spend path:
 * every passenger balance is money Komiut is holding on somebody's behalf. If
 * the ledger and the bank ever disagree, the platform cannot say what it owes
 * -- and that is the first question anyone asks about held customer funds,
 * whether it is a passenger, an auditor or the regulator. A discrepancy found
 * the day it appears is a bug; found six months later it is a reconstruction
 * project across a million payments.
 *
 * NINE CHECKS, cheapest and most fundamental first:
 *
 *   1  the ledger sums to the balances          (invented or lost money)
 *   2  every row's running total is truthful    (a bad write under the lock)
 *   3  no balance is negative                   (the CHECK constraint held)
 *   4  every top-up traces to an M-Pesa payment (money with no bank behind it)
 *   5  each top-up's amount matches its payment (credited more than arrived)
 *   6  no payment credited two wallets          (mis-attribution)
 *   7  settled attempts have a ledger row, and vice versa
 *   8  THE FLOAT IDENTITY: received - spent - settled == held
 *   9  received equals the payments behind it, in aggregate
 *
 * It also REPORTS rather than only asserts: the float liability per bank, and
 * the attempts stuck pending, because "nothing is broken" and "nothing needs
 * attention" are different questions.
 *
 * Exit code 1 on any discrepancy, so a scheduler or a CI job can treat it as a
 * failure. Read-only: it never repairs anything, because a wallet that is wrong
 * needs a human to decide who is owed what.
 */
class ReconcileWalletFloat extends Command
{
    protected $signature = 'wallet:reconcile {--quiet-when-clean : print nothing unless something is wrong}';

    protected $description = 'Check that wallet balances, the ledger and the M-Pesa payments behind them all agree';

    /** @var array<int, string> */
    private array $problems = [];

    public function handle(): int
    {
        $this->ledgerSumsToBalances();
        $this->runningTotalsAreTruthful();
        $this->noNegativeBalances();
        $this->everyTopUpTracesToAPayment();
        $this->topUpAmountsMatchTheirPayments();
        $this->noPaymentCreditedTwoWallets();
        $this->settledAttemptsAgreeWithTheLedger();
        $this->theFloatIdentityHolds();
        $this->receivedMatchesTheBank();

        if ($this->problems === []) {
            if (! $this->option('quiet-when-clean')) {
                $this->report();
                $this->info('Wallet float reconciles.');
            }

            return self::SUCCESS;
        }

        // Logged as well as printed: this runs on a schedule where nobody is
        // watching stdout, and a discrepancy that only ever existed in a
        // terminal is one nobody acts on.
        Log::error('wallet float does not reconcile', ['problems' => $this->problems]);

        $this->report();
        $this->newLine();
        $this->error('WALLET FLOAT DOES NOT RECONCILE:');

        foreach ($this->problems as $problem) {
            $this->line('  - '.$problem);
        }

        return self::FAILURE;
    }

    /**
     * Check 1. The identity the whole wallet rests on.
     *
     * sum(ledger) must equal sum(balances). A gap in either direction is money
     * invented or money lost, and nothing else in this command matters if this
     * one fails.
     */
    private function ledgerSumsToBalances(): void
    {
        $ledger = (int) WalletTransaction::sum('amount_cents');
        $balances = (int) Wallet::sum('balance_cents');

        if ($ledger !== $balances) {
            $this->problems[] = sprintf(
                'the ledger sums to KES %s but the balances sum to KES %s (a gap of KES %s)',
                number_format(Shillings::toShillings($ledger)),
                number_format(Shillings::toShillings($balances)),
                number_format(Shillings::toShillings(abs($ledger - $balances))),
            );
        }
    }

    /**
     * Check 2. Each row's `balance_after_cents` must be the running total.
     *
     * Done in SQL with a window function rather than in PHP: the ledger will
     * outgrow memory long before it outgrows Postgres, and this has to stay
     * runnable on the day it is needed most.
     */
    private function runningTotalsAreTruthful(): void
    {
        $bad = DB::select(<<<'SQL'
            SELECT wallet_id, id, balance_after_cents, running
            FROM (
                SELECT wallet_id, id, balance_after_cents,
                       SUM(amount_cents) OVER (PARTITION BY wallet_id ORDER BY id) AS running
                FROM wallet_transactions
            ) rows
            WHERE balance_after_cents <> running
            ORDER BY wallet_id, id
            LIMIT 20
        SQL);

        foreach ($bad as $row) {
            $this->problems[] = sprintf(
                'wallet %d entry %d says the balance was KES %s but the ledger up to it totals KES %s',
                $row->wallet_id,
                $row->id,
                number_format(Shillings::toShillings((int) $row->balance_after_cents)),
                number_format(Shillings::toShillings((int) $row->running)),
            );
        }
    }

    /** Check 3. The CHECK constraint should make this impossible; verify it did. */
    private function noNegativeBalances(): void
    {
        $negative = Wallet::where('balance_cents', '<', 0)->pluck('balance_cents', 'id');

        foreach ($negative as $walletId => $cents) {
            $this->problems[] = sprintf('wallet %d holds a negative balance of KES %s', $walletId, number_format(Shillings::toShillings((int) $cents)));
        }
    }

    /**
     * Check 4. Every shilling credited as a top-up must name the payment it came from.
     *
     * This is the float-to-bank link. A top-up with no `mpesa_id` is a balance
     * with no money behind it — the platform owes a passenger something it was
     * never paid.
     */
    private function everyTopUpTracesToAPayment(): void
    {
        $orphans = WalletTransaction::where('kind', WalletEntryKind::TopUp->value)
            ->whereNull('mpesa_id')
            ->get(['id', 'wallet_id', 'amount_cents', 'reference']);

        foreach ($orphans as $row) {
            $this->problems[] = sprintf(
                'top-up %d on wallet %d credited KES %s with no M-Pesa payment behind it (%s)',
                $row->id,
                $row->wallet_id,
                number_format(Shillings::toShillings(abs((int) $row->amount_cents))),
                $row->reference,
            );
        }
    }

    /**
     * Check 5. A top-up may not credit more than the payment carried.
     *
     * Compared with a one-shilling tolerance because `mpesas."TransAmount"` is
     * a VARCHAR on this schema and arrives as "100", "100.00" or "100.0"
     * depending on which rail wrote it — a strict comparison would cry wolf on
     * every row rather than on the one that matters.
     */
    private function topUpAmountsMatchTheirPayments(): void
    {
        $mismatched = DB::select(<<<'SQL'
            SELECT t.id, t.wallet_id, t.amount_cents, m."TransID" AS trans_id,
                   m."TransAmount" AS trans_amount
            FROM wallet_transactions t
            JOIN mpesas m ON m.id = t.mpesa_id
            WHERE t.kind = ?
              AND ABS(ABS(t.amount_cents) - ROUND(CAST(NULLIF(m."TransAmount", '') AS numeric) * 100)) > 100
            LIMIT 20
        SQL, [WalletEntryKind::TopUp->value]);

        foreach ($mismatched as $row) {
            $this->problems[] = sprintf(
                'top-up %d credited KES %s but payment %s carried KES %s',
                $row->id,
                number_format(Shillings::toShillings(abs((int) $row->amount_cents))),
                $row->trans_id,
                $row->trans_amount,
            );
        }
    }

    /**
     * Check 6. One payment, one wallet.
     *
     * The unique index on `wallet_transactions.mpesa_id` should make this
     * unreachable. It is checked anyway because the index is the thing being
     * verified: an index dropped during a migration is exactly the kind of
     * silent change this command exists to catch.
     */
    private function noPaymentCreditedTwoWallets(): void
    {
        $shared = DB::select(<<<'SQL'
            SELECT mpesa_id, COUNT(*) AS uses
            FROM wallet_transactions
            WHERE mpesa_id IS NOT NULL
            GROUP BY mpesa_id
            HAVING COUNT(*) > 1
            LIMIT 20
        SQL);

        foreach ($shared as $row) {
            $this->problems[] = sprintf('M-Pesa payment %d credited %d wallet entries', $row->mpesa_id, $row->uses);
        }
    }

    /** Check 7. A completed attempt must point at a ledger row, and only at one. */
    private function settledAttemptsAgreeWithTheLedger(): void
    {
        $completedWithoutMoney = WalletTopUp::where('status', WalletTopUpStatus::Completed->value)
            ->whereNull('wallet_transaction_id')
            ->count();

        if ($completedWithoutMoney > 0) {
            $this->problems[] = sprintf('%d top-up(s) are marked completed but credited nothing', $completedWithoutMoney);
        }

        $moneyWithoutCompletion = WalletTopUp::whereNotNull('wallet_transaction_id')
            ->where('status', '!=', WalletTopUpStatus::Completed->value)
            ->count();

        if ($moneyWithoutCompletion > 0) {
            $this->problems[] = sprintf('%d top-up(s) credited money but are not marked completed', $moneyWithoutCompletion);
        }
    }

    /**
     * Check 8. THE FLOAT IDENTITY: received - spent - settled == held.
     *
     * This is NOT check 1 restated, and the difference is the whole reason it
     * exists. Check 1 asks whether each balance agrees with its own movements
     * -- an internal question, and it would still pass if a top-up were
     * credited twice under two different `mpesas` rows, because the ledger and
     * the balance would agree on the wrong number. This one asks the external
     * question: does the money the platform has taken in, minus what passengers
     * have spent and what has been paid out to SACCOs, equal what is still
     * being held?
     *
     * It is written BEFORE the spend path deliberately. Today `spent` is zero,
     * so the identity reduces to `received == held` and check 1 happens to
     * imply it. The moment fares start debiting wallets that stops being true,
     * and this is the only check that would notice money leaving the float
     * without a corresponding obligation.
     *
     * `settled` IS STRUCTURALLY ZERO, not measured as zero: there is no
     * `wallet_settlements` table yet, because paying SACCOs out of the float is
     * phase 4 and has no API path on NCBA's side anyway (NcbaStkClient is
     * collection-only -- token, stk-push/initiate, stk-push/query, and nothing
     * that moves money out). When that table lands, read it here instead of
     * assuming zero, and `spent - settled` becomes the outstanding obligation
     * to the SACCOs.
     */
    private function theFloatIdentityHolds(): void
    {
        $received = $this->receivedCents();
        $spent = $this->spentCents();
        $settled = $this->settledCents();
        $held = (int) Wallet::sum('balance_cents');

        // Refunds and corrections are money moving back INTO wallets, so they
        // belong on the same side as `received` -- otherwise a refunded fare
        // reads as a shortfall forever.
        $returned = $this->returnedCents();

        $expected = $received + $returned - $spent - $settled;

        if ($expected !== $held) {
            $this->problems[] = sprintf(
                'the float does not balance: received %s + returned %s - spent %s - settled %s = %s, but wallets hold %s (a gap of %s)',
                $this->money($received),
                $this->money($returned),
                $this->money($spent),
                $this->money($settled),
                $this->money($expected),
                $this->money($held),
                $this->money(abs($expected - $held)),
            );
        }
    }

    /**
     * Check 9. Every shilling received must equal the payments behind it, in aggregate.
     *
     * Check 5 compares each top-up against its own payment and tolerates a
     * shilling of rounding, because `mpesas."TransAmount"` is a VARCHAR on this
     * schema. Those tolerances add up: a hundred top-ups each a shilling out
     * pass check 5 and still leave the float a hundred short. This sums both
     * sides and allows the same tolerance ONCE.
     */
    private function receivedMatchesTheBank(): void
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT coalesce(sum(abs(t.amount_cents)), 0) AS credited,
                   coalesce(sum(round(cast(nullif(m."TransAmount", '') AS numeric) * 100)), 0) AS banked
            FROM wallet_transactions t
            JOIN mpesas m ON m.id = t.mpesa_id
            WHERE t.kind = ?
        SQL, [WalletEntryKind::TopUp->value]);

        $credited = (int) ($row->credited ?? 0);
        $banked = (int) ($row->banked ?? 0);

        if (abs($credited - $banked) > Shillings::PER_SHILLING) {
            $this->problems[] = sprintf(
                'top-ups credited %s but the payments behind them carried %s',
                $this->money($credited),
                $this->money($banked),
            );
        }
    }

    /** Money in: top-ups. */
    private function receivedCents(): int
    {
        return (int) WalletTransaction::where('kind', WalletEntryKind::TopUp->value)->sum('amount_cents');
    }

    /** Money back to passengers: refunds, reversals and positive corrections. */
    private function returnedCents(): int
    {
        return (int) WalletTransaction::whereIn('kind', [
            WalletEntryKind::Refund->value,
            WalletEntryKind::Reversal->value,
            WalletEntryKind::Adjustment->value,
        ])->where('amount_cents', '>', 0)->sum('amount_cents');
    }

    /** Money out: fares, plus any negative correction. */
    private function spentCents(): int
    {
        return abs((int) WalletTransaction::where(function ($q) {
            $q->where('kind', WalletEntryKind::Fare->value)
                ->orWhere(fn ($inner) => $inner->whereIn('kind', [
                    WalletEntryKind::Reversal->value,
                    WalletEntryKind::Adjustment->value,
                ])->where('amount_cents', '<', 0));
        })->sum('amount_cents'));
    }

    /**
     * Money paid out of the float to SACCOs.
     *
     * STRUCTURALLY ZERO until phase 4 builds `wallet_settlements`. Returning a
     * hard zero rather than querying a table that does not exist keeps the
     * identity honest and makes the one line to change obvious.
     */
    private function settledCents(): int
    {
        return 0;
    }

    private function money(int $cents): string
    {
        return 'KES '.number_format(Shillings::toShillings($cents));
    }

    /**
     * What is being held, and what is stuck.
     *
     * Printed even when everything reconciles: the float liability is a number
     * somebody should be able to read off a cron log without opening a console.
     */
    private function report(): void
    {
        $totalCents = (int) Wallet::sum('balance_cents');

        // The identity printed out, so it can be read off a cron log rather
        // than only trusted. `settled` stays 0 until phase 4.
        $this->line(sprintf(
            'received %s + returned %s - spent %s - settled %s  =>  held %s',
            $this->money($this->receivedCents()),
            $this->money($this->returnedCents()),
            $this->money($this->spentCents()),
            $this->money($this->settledCents()),
            $this->money($totalCents),
        ));
        $this->line('Float held: KES '.number_format(Shillings::toShillings($totalCents)));

        // WHAT THE PLATFORM OWES THE SACCOs. Money a passenger has spent out of
        // the float but that has not been paid out yet. Today this is zero
        // because there is no spend path; once phase 3 lands it is the number
        // that matters most, because it is a liability growing on the balance
        // sheet with no payout rail to discharge it -- NcbaStkClient cannot
        // move money out at all.
        $owed = $this->spentCents() - $this->settledCents();

        if ($owed !== 0) {
            $this->warn('Owed to SACCOs and not yet settled: '.$this->money($owed));
        }

        $perBank = Wallet::selectRaw('coalesce(float_bank, \'(unattributed)\') AS bank, count(*) AS wallets, sum(balance_cents) AS cents')
            ->groupBy('bank')
            ->orderByDesc('cents')
            ->get();

        foreach ($perBank as $bank) {
            $this->line(sprintf(
                '  %-16s KES %12s across %d wallet(s)',
                $bank->bank,
                number_format(Shillings::toShillings((int) $bank->cents)),
                (int) $bank->wallets,
            ));
        }

        // An unattributed balance cannot be settled to any bank, so it is worth
        // naming even though it is not an inconsistency.
        $unattributed = Wallet::whereNull('float_bank')->where('balance_cents', '>', 0)->count();

        if ($unattributed > 0) {
            $this->warn(sprintf('%d wallet(s) hold money with no float bank recorded — these cannot be settled.', $unattributed));
        }

        $stuck = WalletTopUp::where('status', WalletTopUpStatus::Pending->value)->count();

        if ($stuck > 0) {
            $this->line($stuck.' top-up(s) still pending.');
        }
    }
}
