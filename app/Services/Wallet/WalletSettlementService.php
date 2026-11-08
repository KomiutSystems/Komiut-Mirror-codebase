<?php

declare(strict_types=1);

namespace App\Services\Wallet;

use App\Enums\WalletEntryKind;
use App\Enums\WalletSettlementStatus;
use App\Models\WalletSettlement;
use App\Models\WalletTransaction;
use App\Services\Platform\AuditLogger;
use App\Support\BusinessDay;
use App\Support\Shillings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turning wallet fares into money owed, and then into money sent.
 *
 * WHAT THIS EXISTS TO PREVENT. A wallet fare moves a passenger's money out of
 * the float and gives the ride to a SACCO's bus. Without an accrual the
 * obligation lives nowhere: `wallet:reconcile` can only report `spent` and
 * assume `settled = 0`, which holds exactly until the first payout and is
 * silently wrong from then on. Worse, nobody can answer "what do we owe?" --
 * the one question anyone holding customer funds has to be able to answer on
 * demand.
 *
 * THE SETTLE-ONCE GUARANTEE is `wallet_transactions.wallet_settlement_id`. The
 * accrual only ever claims fares where it is NULL, and sets it inside the same
 * transaction that writes the settlement row. A second run over the same day
 * finds nothing left, so the sweep is safe to run on a schedule and safe to
 * re-run by hand after a failure.
 *
 * PER (SACCO, FINANCIER, BUSINESS DAY), because the two banks reconcile
 * separately -- NICCO alone runs 126 NCBA-financed vehicles and 54 Co-op ones
 * (App\Enums\Financier). A single row per SACCO per day would mix two banks'
 * money into one payout neither could check.
 *
 * NOTHING HERE SENDS MONEY. Accrual states what is owed; a WalletPayoutRail
 * sends it, and only from an APPROVED row. That separation is deliberate: the
 * figures come from a sweep over somebody else's money, and the first run after
 * a bug must not be able to pay out before a human has read the number.
 */
final class WalletSettlementService
{
    /**
     * Accrue every unsettled fare up to (but not including) the current
     * business day.
     *
     * TODAY IS DELIBERATELY EXCLUDED. A business day that is still running
     * would accrue a partial figure, get approved and paid, and then collect
     * more fares with no row left to claim them -- they would sit unsettled
     * forever behind an already-paid period. Only closed days are accrued.
     *
     * @return array{settlements: int, fares: int, cents: int}
     */
    public function accrue(?Carbon $upTo = null, int $maxDays = 14): array
    {
        [$currentDayStart] = BusinessDay::windowFor($upTo ?? Carbon::now());

        $oldest = WalletTransaction::query()
            ->where('kind', WalletEntryKind::Fare->value)
            ->whereNull('wallet_settlement_id')
            ->whereNotNull('sacco_id')
            ->where('created_at', '<', $currentDayStart)
            ->min('created_at');

        if ($oldest === null) {
            return ['settlements' => 0, 'fares' => 0, 'cents' => 0];
        }

        [$cursor] = BusinessDay::windowFor(Carbon::parse($oldest));

        $settlements = 0;
        $fares = 0;
        $cents = 0;

        // BOUNDED, for the reason AttributeCoopSettlements carries a window: an
        // unattended run must not be able to walk the whole table if a backfill
        // drops a year of history into it.
        for ($day = 0; $day < $maxDays && $cursor->lt($currentDayStart); $day++) {
            $dayEnd = $cursor->copy()->addDay();

            foreach ($this->groupsFor($cursor, $dayEnd) as $group) {
                $row = $this->accrueGroup(
                    (int) $group->sacco_id,
                    $group->financier === null ? null : (string) $group->financier,
                    $cursor->copy(),
                    $dayEnd->copy(),
                );

                if ($row !== null) {
                    $settlements++;
                    $fares += (int) $row->fare_count;
                    $cents += (int) $row->gross_cents;
                }
            }

            $cursor = $dayEnd;
        }

        return ['settlements' => $settlements, 'fares' => $fares, 'cents' => $cents];
    }

    /**
     * The (sacco, financier) pairs with unsettled fares in this window.
     *
     * financier is grouped on as-is, NULL included: a bus no bank finances still
     * earned its fare and still has to be paid.
     *
     * @return Collection<int, object>
     */
    private function groupsFor(Carbon $from, Carbon $to)
    {
        return WalletTransaction::query()
            ->selectRaw('sacco_id, financier')
            ->where('kind', WalletEntryKind::Fare->value)
            ->whereNull('wallet_settlement_id')
            ->whereNotNull('sacco_id')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->groupBy('sacco_id', 'financier')
            ->get();
    }

    /**
     * Claim one group's fares into one settlement.
     *
     * THE CLAIM AND THE ROW COMMIT TOGETHER. The fares are re-selected FOR
     * UPDATE inside the transaction and their ids drive both the totals and the
     * claim, so a concurrent run cannot have the same fare counted here and
     * claimed there. Totals are summed from the rows actually claimed, never
     * from a separate aggregate that could disagree with them.
     */
    private function accrueGroup(int $saccoId, ?string $financier, Carbon $from, Carbon $to): ?WalletSettlement
    {
        return DB::transaction(function () use ($saccoId, $financier, $from, $to) {
            $fares = WalletTransaction::query()
                ->where('kind', WalletEntryKind::Fare->value)
                ->whereNull('wallet_settlement_id')
                ->where('sacco_id', $saccoId)
                ->when($financier === null,
                    fn ($q) => $q->whereNull('financier'),
                    fn ($q) => $q->where('financier', $financier))
                ->where('created_at', '>=', $from)
                ->where('created_at', '<', $to)
                ->lockForUpdate()
                ->get(['id', 'amount_cents']);

            if ($fares->isEmpty()) {
                return null;
            }

            // Fares are stored negative (a debit); what is OWED is the magnitude.
            $gross = $fares->sum(fn (WalletTransaction $f) => abs((int) $f->amount_cents));

            $settlement = WalletSettlement::create([
                'sacco_id' => $saccoId,
                'financier' => $financier,
                'period_start' => $from,
                'period_end' => $to,
                'gross_cents' => $gross,
                'fare_count' => $fares->count(),
                'status' => WalletSettlementStatus::Pending,
                'reference' => $this->referenceFor($saccoId, $financier, $from),
            ]);

            WalletTransaction::whereIn('id', $fares->pluck('id'))
                ->update(['wallet_settlement_id' => $settlement->id]);

            return $settlement;
        });
    }

    /**
     * The payout idempotency key.
     *
     * DERIVED, NOT RANDOM, so that a re-accrual of the same period after a
     * rolled-back run produces the same key and collides on the unique index
     * instead of minting a second payable row for money already owed once.
     */
    private function referenceFor(int $saccoId, ?string $financier, Carbon $from): string
    {
        return 'WS-'.$from->format('Ymd').'-'.$saccoId.'-'.($financier === null ? 'none' : strtolower(str_replace('-', '', $financier)));
    }

    /**
     * A human agrees the figure. Payable from here.
     *
     * @throws WalletPaymentRefused
     */
    public function approve(WalletSettlement $settlement, int $actorId): WalletSettlement
    {
        if ($settlement->status === WalletSettlementStatus::Paid) {
            throw new WalletPaymentRefused(422, 'This settlement is already paid.');
        }

        $settlement->status = WalletSettlementStatus::Approved;
        $settlement->approved_at = Carbon::now();
        $settlement->approved_by = $actorId;
        $settlement->failure_reason = null;
        $settlement->save();

        AuditLogger::record(
            'wallet.settlement.approved',
            [
                'settlement_id' => $settlement->id,
                'gross' => $settlement->grossShillings(),
                'fares' => (int) $settlement->fare_count,
                'bank' => $settlement->bankLabel(),
                'period' => $settlement->period_start?->toDateString(),
            ],
            subject: ['type' => 'wallet_settlement', 'id' => $settlement->id],
            saccoId: (int) $settlement->sacco_id,
        );

        return $settlement;
    }

    /**
     * Record that the money has gone.
     *
     * A PAYOUT REFERENCE IS MANDATORY. "Paid" with nothing to point at is an
     * assertion nobody can check later, and this row is the only record that a
     * SACCO was settled for a given day. Whether the money moved by API or by
     * somebody typing a transfer into online banking, there is always a
     * reference, and it goes here.
     *
     * @throws WalletPaymentRefused
     */
    public function markPaid(WalletSettlement $settlement, string $payoutReference, string $rail, int $actorId): WalletSettlement
    {
        if ($settlement->status === WalletSettlementStatus::Paid) {
            // NOT an error if it names the same payment: a retried click must
            // not read as a failure. A DIFFERENT reference is a real conflict --
            // somebody is about to record a second payment for one debt.
            if (trim($payoutReference) === trim((string) $settlement->payout_reference)) {
                return $settlement;
            }

            throw new WalletPaymentRefused(422, 'This settlement is already paid under reference '.$settlement->payout_reference.'.');
        }

        if (! $settlement->status->isPayable()) {
            throw new WalletPaymentRefused(422, 'Approve this settlement before recording a payment.');
        }

        if (trim($payoutReference) === '') {
            throw new WalletPaymentRefused(422, 'Record the payout reference.');
        }

        $settlement->status = WalletSettlementStatus::Paid;
        $settlement->payout_reference = trim($payoutReference);
        $settlement->payout_rail = $rail;
        $settlement->paid_at = Carbon::now();
        $settlement->paid_by = $actorId;
        $settlement->failure_reason = null;
        $settlement->save();

        AuditLogger::record(
            'wallet.settlement.paid',
            [
                'settlement_id' => $settlement->id,
                'gross' => $settlement->grossShillings(),
                'payout_reference' => $settlement->payout_reference,
                'rail' => $rail,
                'bank' => $settlement->bankLabel(),
            ],
            subject: ['type' => 'wallet_settlement', 'id' => $settlement->id],
            saccoId: (int) $settlement->sacco_id,
        );

        return $settlement;
    }

    /** A payout the rail refused. Retryable; the money has not moved. */
    public function markFailed(WalletSettlement $settlement, string $reason): WalletSettlement
    {
        if ($settlement->status === WalletSettlementStatus::Paid) {
            return $settlement;
        }

        $settlement->status = WalletSettlementStatus::Failed;
        $settlement->failure_reason = mb_substr($reason, 0, 200);
        $settlement->save();

        AuditLogger::record(
            'wallet.settlement.failed',
            ['settlement_id' => $settlement->id, 'reason' => $settlement->failure_reason],
            subject: ['type' => 'wallet_settlement', 'id' => $settlement->id],
            saccoId: (int) $settlement->sacco_id,
        );

        return $settlement;
    }

    /** What is owed and not yet paid, in cents. The float's outstanding liability. */
    public function outstandingCents(): int
    {
        return (int) WalletSettlement::where('status', '!=', WalletSettlementStatus::Paid->value)->sum('gross_cents');
    }

    /** What has been paid out, in cents. Feeds wallet:reconcile's float identity. */
    public function settledCents(): int
    {
        return (int) WalletSettlement::where('status', WalletSettlementStatus::Paid->value)->sum('gross_cents');
    }

    /**
     * Fares that are owed but have no settlement row yet.
     *
     * Not a bug on its own -- today's fares are deliberately not accrued until
     * the business day closes. It IS a bug if it covers closed days, which is
     * what the reconciliation check uses it for.
     */
    public function unaccruedCents(): int
    {
        return abs((int) WalletTransaction::query()
            ->where('kind', WalletEntryKind::Fare->value)
            ->whereNull('wallet_settlement_id')
            ->sum('amount_cents'));
    }

    public function unaccruedShillings(): int
    {
        return Shillings::toShillings($this->unaccruedCents());
    }
}
