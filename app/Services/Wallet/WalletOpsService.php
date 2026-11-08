<?php

declare(strict_types=1);

namespace App\Services\Wallet;

use App\Enums\WalletEntryKind;
use App\Enums\WalletSettlementStatus;
use App\Enums\WalletStatus;
use App\Events\PassengerBalanceChanged;
use App\Models\Vehicle;
use App\Models\Wallet;
use App\Models\WalletSettlement;
use App\Models\WalletTransaction;
use App\Services\Platform\AuditLogger;
use App\Support\Shillings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The operator's hands on a passenger's wallet: hold it, refund a ride, correct
 * a balance.
 *
 * EVERY METHOD HERE WRITES AN AUDIT ROW, and that is the point of the class
 * rather than a nicety. These are the only paths by which someone who is not
 * the passenger moves the passenger's money, so each one has to answer "who,
 * when, how much and why" months later, to a passenger complaining or an
 * auditor asking. A reason is mandatory on every one; there is no default and
 * no empty string allowed.
 *
 * NOTHING HERE BYPASSES WalletService. Freezes aside, money moves only through
 * credit()/debit() under the wallet row lock, so an operator correction obeys
 * the same ledger, the same replay guard and the same balance constraint as a
 * passenger's own fare.
 */
final class WalletOpsService
{
    public function __construct(private WalletService $wallet) {}

    /**
     * Put a wallet on hold.
     *
     * A FROZEN WALLET STILL RECEIVES. It cannot pay (WalletService::move
     * refuses a debit), but a top-up already paid for and a refund owed must
     * still land -- refusing them would strand real money outside the ledger
     * with nothing to reconcile it against.
     */
    public function freeze(int $userId, string $reason, int $actorId): Wallet
    {
        $reason = $this->requireReason($reason);

        $wallet = DB::transaction(function () use ($userId, $reason) {
            $locked = $this->wallet->lock($userId);
            $locked->status = WalletStatus::Frozen;
            $locked->frozen_at = Carbon::now();
            $locked->frozen_reason = $reason;
            $locked->save();

            return $locked;
        });

        AuditLogger::record(
            'wallet.frozen',
            [
                'user_id' => $userId,
                'reason' => $reason,
                // The balance at the moment of the hold, so a later dispute can
                // tell what was held from what moved afterwards.
                'balance' => $wallet->balanceShillings(),
            ],
            actor: ['type' => 'user', 'id' => $actorId],
            subject: ['type' => 'wallet', 'id' => $wallet->id],
        );

        return $wallet;
    }

    public function unfreeze(int $userId, string $reason, int $actorId): Wallet
    {
        $reason = $this->requireReason($reason);

        $wallet = DB::transaction(function () use ($userId) {
            $locked = $this->wallet->lock($userId);
            $locked->status = WalletStatus::Active;
            $locked->frozen_at = null;
            $locked->frozen_reason = null;
            $locked->save();

            return $locked;
        });

        AuditLogger::record(
            'wallet.unfrozen',
            ['user_id' => $userId, 'reason' => $reason, 'balance' => $wallet->balanceShillings()],
            actor: ['type' => 'user', 'id' => $actorId],
            subject: ['type' => 'wallet', 'id' => $wallet->id],
        );

        return $wallet;
    }

    /**
     * Give a fare back.
     *
     * THE HARD PART IS NOT THE PASSENGER, IT IS THE SACCO. A wallet fare is
     * accrued into a settlement and then paid out. Refunding the passenger
     * therefore has three quite different consequences depending on how far
     * that has got, and getting it wrong means either paying a SACCO for a ride
     * that was refunded or clawing back money they were owed:
     *
     *   not yet accrued   nothing else to do; the fare simply never accrues.
     *   accrued, unpaid   the settlement is REDUCED by this fare and the fare is
     *                     detached, so we never pay for a refunded ride. A
     *                     settlement reduced to nothing is deleted outright,
     *                     because gross_cents has a CHECK (> 0) and a zero
     *                     payout is not a payout.
     *   already PAID      the SACCO has the money. Nothing is adjusted -- a paid
     *                     settlement is immutable -- and the audit row says in
     *                     as many words that this needs a manual clawback. The
     *                     passenger is still refunded: they are not the ones
     *                     who should wait on a reconciliation.
     *
     * @throws WalletPaymentRefused
     */
    public function refundFare(int $fareId, string $reason, int $actorId): array
    {
        $reason = $this->requireReason($reason);

        $fare = WalletTransaction::with('wallet')->findOrFail($fareId);

        if ($fare->kind !== WalletEntryKind::Fare) {
            throw new WalletPaymentRefused(422, 'Only a fare can be refunded.');
        }

        $wallet = $fare->wallet;

        if ($wallet === null) {
            throw new WalletPaymentRefused(422, 'That fare has no wallet behind it.');
        }

        $amountCents = abs((int) $fare->amount_cents);
        $settlement = $fare->wallet_settlement_id === null
            ? null
            : WalletSettlement::find($fare->wallet_settlement_id);

        $alreadyPaidOut = $settlement !== null && $settlement->isPaid();

        $entry = DB::transaction(function () use ($fare, $wallet, $amountCents, $reason, $actorId, $settlement, $alreadyPaidOut) {
            $credit = $this->wallet->credit(
                (int) $wallet->user_id,
                $amountCents,
                WalletEntryKind::Refund,
                // Keyed on the FARE, so a repeated refund of one ride replays
                // the first credit instead of paying it back twice.
                'refund:fare:'.$fare->id,
                [
                    'title' => $this->refundTitle($fare),
                    'detail' => mb_substr($reason, 0, 190),
                    'booking_id' => $fare->booking_id,
                    'vehicle_id' => $fare->vehicle_id,
                    'sacco_id' => $fare->sacco_id,
                    'financier' => $fare->financier,
                    'actor_id' => $actorId,
                    'meta' => [
                        'refunded_fare_id' => $fare->id,
                        'reason' => $reason,
                        'settlement_id' => $settlement?->id,
                        'already_paid_out' => $alreadyPaidOut,
                        // Carried over so receiptCode() resolves the refund to
                        // the SAME receipt as the fare it reverses.
                        'qrcode_payment_id' => is_array($fare->meta) ? ($fare->meta['qrcode_payment_id'] ?? null) : null,
                    ],
                ],
            );

            if ($settlement !== null && ! $alreadyPaidOut) {
                $this->reduceSettlement($settlement, $fare, $amountCents);
            }

            return $credit;
        });

        PassengerBalanceChanged::wallet(
            userId: (int) $wallet->user_id,
            balanceShillings: $entry->balanceAfterShillings(),
            deltaShillings: $entry->amountShillings(),
            reason: WalletEntryKind::Refund->value,
        );

        AuditLogger::record(
            // A refund of a ride the SACCO has ALREADY BEEN PAID for is a
            // different event, because it needs a human to recover money. Giving
            // it its own action means it can be alerted on rather than found.
            $alreadyPaidOut ? 'wallet.refund.after_settlement' : 'wallet.refund',
            [
                'user_id' => (int) $wallet->user_id,
                'fare_id' => $fare->id,
                'amount' => Shillings::toShillings($amountCents),
                'reason' => $reason,
                'settlement_id' => $settlement?->id,
                'needs_clawback' => $alreadyPaidOut,
                'note' => $alreadyPaidOut
                    ? 'The SACCO was already paid for this ride. Recover it manually; the settlement is immutable.'
                    : null,
            ],
            actor: ['type' => 'user', 'id' => $actorId],
            subject: ['type' => 'wallet', 'id' => $wallet->id],
            saccoId: $fare->sacco_id === null ? null : (int) $fare->sacco_id,
        );

        return ['entry' => $entry, 'needs_clawback' => $alreadyPaidOut, 'settlement' => $settlement];
    }

    /**
     * Take a refunded fare back out of an unpaid settlement.
     *
     * Deleted rather than zeroed when it empties: `gross_cents` carries a CHECK
     * (> 0), and a settlement for nothing would sit in the payout queue forever
     * inviting somebody to approve it.
     */
    /** 'Refund · KDC 482M', per the app's contract -- the bus, not a bare word. */
    private function refundTitle(WalletTransaction $fare): string
    {
        $plate = $fare->vehicle_id === null
            ? null
            : Vehicle::withoutGlobalScopes()->whereKey($fare->vehicle_id)->value('plate');

        $plate = trim((string) $plate);

        return $plate === '' ? 'Refund' : 'Refund · '.$plate;
    }

    private function reduceSettlement(WalletSettlement $settlement, WalletTransaction $fare, int $amountCents): void
    {
        $fresh = WalletSettlement::whereKey($settlement->id)->lockForUpdate()->first();

        if ($fresh === null || $fresh->isPaid()) {
            // It was paid while we waited on the lock. Leave it alone; the audit
            // row already records which settlement this fare belonged to.
            return;
        }

        $fare->wallet_settlement_id = null;
        $fare->save();

        $remaining = (int) $fresh->gross_cents - $amountCents;
        $fares = max(0, (int) $fresh->fare_count - 1);

        if ($remaining <= 0 || $fares === 0) {
            $fresh->delete();

            return;
        }

        $fresh->gross_cents = $remaining;
        $fresh->fare_count = $fares;
        // Back to pending: an approved figure that has since changed has not
        // been agreed by anyone.
        $fresh->status = WalletSettlementStatus::Pending;
        $fresh->approved_at = null;
        $fresh->approved_by = null;
        $fresh->save();
    }

    /**
     * Correct a balance by hand.
     *
     * THE MOST DANGEROUS METHOD IN THE WALLET, so it is the most constrained: a
     * reason is mandatory, the actor is recorded, the audit event is its own,
     * and the movement goes through the ordinary ledger so it cannot produce a
     * balance the ledger disagrees with or push one below zero.
     *
     * A POSITIVE amount credits, a negative one debits. There is no "set the
     * balance to X": that would be unauditable, because the ledger would show a
     * jump with no movement explaining it.
     *
     * @throws WalletPaymentRefused
     */
    public function adjust(int $userId, int $shillings, string $reason, int $actorId): WalletTransaction
    {
        $reason = $this->requireReason($reason);

        if ($shillings === 0) {
            throw new WalletPaymentRefused(422, 'An adjustment of nothing is not an adjustment.');
        }

        $cents = Shillings::toCents(abs($shillings));
        $credit = $shillings > 0;

        // A unique reference per adjustment, since there is nothing else to key
        // one on -- two separate corrections of the same amount for the same
        // passenger are two different events, not a replay.
        $reference = 'adjust:'.$userId.':'.Carbon::now()->format('YmdHisu');

        $entry = $credit
            ? $this->wallet->credit($userId, $cents, WalletEntryKind::Adjustment, $reference, [
                'title' => 'Adjustment',
                'detail' => mb_substr($reason, 0, 190),
                'actor_id' => $actorId,
                'meta' => ['reason' => $reason, 'direction' => 'credit'],
            ])
            : $this->wallet->debit($userId, $cents, WalletEntryKind::Adjustment, $reference, [
                'title' => 'Adjustment',
                'detail' => mb_substr($reason, 0, 190),
                'actor_id' => $actorId,
                'meta' => ['reason' => $reason, 'direction' => 'debit'],
            ]);

        PassengerBalanceChanged::wallet(
            userId: $userId,
            balanceShillings: $entry->balanceAfterShillings(),
            deltaShillings: $credit ? $entry->amountShillings() : -$entry->amountShillings(),
            reason: WalletEntryKind::Adjustment->value,
        );

        AuditLogger::record(
            'wallet.adjusted',
            [
                'user_id' => $userId,
                'amount' => $credit ? $entry->amountShillings() : -$entry->amountShillings(),
                'balance_after' => $entry->balanceAfterShillings(),
                'reason' => $reason,
            ],
            actor: ['type' => 'user', 'id' => $actorId],
            subject: ['type' => 'wallet', 'id' => (int) $entry->wallet_id],
        );

        return $entry;
    }

    /**
     * No reason, no action.
     *
     * An unexplained movement of somebody else's money is the thing an audit
     * trail exists to make impossible, so this refuses rather than defaulting.
     *
     * @throws WalletPaymentRefused
     */
    private function requireReason(string $reason): string
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 4) {
            throw new WalletPaymentRefused(422, 'Record why. An unexplained change to somebody else\'s money is not allowed.');
        }

        return mb_substr($reason, 0, 500);
    }
}
