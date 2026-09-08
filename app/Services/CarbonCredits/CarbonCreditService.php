<?php

declare(strict_types=1);

namespace App\Services\CarbonCredits;

use App\Enums\CarbonCreditType;
use App\Enums\NotificationType;
use App\Enums\RedemptionStatus;
use App\Events\PassengerBalanceChanged;
use App\Models\Booking;
use App\Models\CarbonCreditAccount;
use App\Models\CarbonCreditRedemption;
use App\Models\CarbonCreditReward;
use App\Models\CarbonCreditTransaction;
use App\Models\LoyaltyTransaction;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Earning and spending platform carbon credits.
 *
 * THE ACCUMULATOR IS THE WHOLE DESIGN. A credit is 1,000 KSh of travel and a
 * matatu fare is 30–150, so crediting per ride and rounding would earn exactly
 * nothing, forever. Each paid ride adds its fare to a carried remainder, and a
 * credit is minted whenever the remainder crosses 1,000 — about a fortnight of
 * commuting. Cents throughout, because this accumulator is added to thousands of
 * times and doubles drift.
 */
class CarbonCreditService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /** Cents of travel per credit. */
    private function rate(): int
    {
        return max(1, (int) config('carbon_credits.ksh_per_credit', 1000)) * 100;
    }

    private function enabled(): bool
    {
        return (bool) config('carbon_credits.enabled', true);
    }

    /**
     * Credit a paid booking. Returns the minted credits (often zero — the fare
     * usually just moves the accumulator along).
     */
    public function earnForBooking(Booking $booking): int
    {
        if (! $this->enabled() || ! $booking->paid || $booking->user_id === null) {
            return 0;
        }

        // A ride paid WITH points moved no money, so it earns nothing. Mirrors
        // LoyaltyService, which makes the same call for the same reason.
        $wasFreeRide = LoyaltyTransaction::withoutGlobalScopes()
            ->where('booking_id', $booking->id)
            ->where('type', 'redeemed')
            ->exists();

        if ($wasFreeRide) {
            return 0;
        }

        $cents = (int) round(((float) $booking->amount) * 100);
        if ($cents <= 0) {
            return 0;
        }

        return $this->accrue((int) $booking->user_id, $cents, (int) $booking->id, null, null);
    }

    /**
     * Credit travel paid for IN THE APP but without a booking — today that means
     * a QR scan on the bus.
     *
     * Carbon credits accrued from BookingPaid alone, so a QR fare earned nothing
     * even though scanning the sticker is exactly the in-app payment the scheme
     * exists to encourage.
     *
     * A DIRECT TILL PAYMENT DELIBERATELY DOES NOT COME THROUGH HERE. Typing a
     * paybill into M-Pesa needs no app and proves no app use, so rewarding it
     * would pay for the behaviour we are trying to change. Earning is for the
     * app's own rails: an STK push against a booking, or a QR scan.
     *
     * Returns the credits minted, which is usually zero — a matatu fare mostly
     * just moves the accumulator along.
     */
    public function earnForFare(int $userId, float $amount, string $sourceType, int $sourceId): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $cents = (int) round($amount * 100);
        if ($cents <= 0) {
            return 0;
        }

        return $this->accrue($userId, $cents, null, $sourceType, $sourceId);
    }

    /**
     * The shared accumulator: add this fare to the carried remainder and mint a
     * credit for every whole rate() it crosses.
     *
     * Keyed on a booking OR a payment source, never neither — the caller must
     * name what produced the credit, because that key is the only thing standing
     * between a replayed webhook and a passenger being paid twice for one ride.
     */
    private function accrue(int $userId, int $cents, ?int $bookingId, ?string $sourceType, ?int $sourceId): int
    {
        if ($bookingId === null && ($sourceType === null || $sourceId === null)) {
            return 0;
        }

        $before = $this->accountFor($userId)->credits;

        $result = DB::transaction(function () use ($userId, $cents, $bookingId, $sourceType, $sourceId): array {
            // Lock the account for the whole read-modify-write: two payments
            // settling at once would otherwise both read the same remainder and
            // one would overwrite the other's progress.
            $account = $this->lockedAccount($userId);

            // The partial unique indexes are the real guard — BookingPaid can
            // fire twice for one booking and Safaricom can deliver a callback
            // twice, and a re-credited ride is money. Checking first turns that
            // into a no-op instead of an exception.
            $already = CarbonCreditTransaction::where('type', CarbonCreditType::Earned)
                ->when(
                    $bookingId !== null,
                    fn ($q) => $q->where('booking_id', $bookingId),
                    fn ($q) => $q->where('source_type', $sourceType)->where('source_id', $sourceId),
                )
                ->exists();

            if ($already) {
                return [
                    'accrued' => false, 'minted' => 0,
                    'credits' => $account->credits, 'progress_cents' => $account->progress_cents,
                ];
            }

            $progress = $account->progress_cents + $cents;
            $minted = intdiv($progress, $this->rate());

            $account->progress_cents = $progress % $this->rate();
            $account->lifetime_spend_cents += $cents;
            $account->credits += $minted;
            $account->save();

            // The ledger records EVERY paid ride, including the ones that minted
            // nothing. Otherwise a passenger cannot see why their balance moved,
            // and neither can we.
            CarbonCreditTransaction::create([
                'user_id' => $userId,
                'credits' => $minted,
                'type' => CarbonCreditType::Earned,
                'spend_cents' => $cents,
                'booking_id' => $bookingId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'description' => $minted > 0
                    ? 'Earned '.$minted.' carbon credit'.($minted === 1 ? '' : 's')
                    : 'Travel counted toward your next credit',
            ]);

            return [
                'accrued' => true, 'minted' => $minted,
                'credits' => $account->credits, 'progress_cents' => $account->progress_cents,
            ];
        });

        // Outside the transaction on purpose: a push must never be able to roll
        // back a credit, and the balance has to be committed before we tell
        // somebody about it.
        //
        // THE QUIET SIGNAL FIRES ON EVERY ACCRUAL, the ones that mint nothing
        // included, because the ledger records those too and the Activity screen
        // shows them. A 150-bob fare moves no credits but does move
        // progress_cents, which is the "X KSh to your next credit" line on the
        // carbon card — real movement the passenger can see. That is precisely
        // the split this event exists for: the milestone PUSH below stays rare
        // (10, 20, 30 — "a push per credit is noise"), while the socket nudge is
        // cheap and says only "your number moved, refetch". A replayed callback
        // accrues nothing and so says nothing.
        if ($result['accrued']) {
            PassengerBalanceChanged::carbon(
                userId: $userId,
                credits: (int) $result['credits'],
                delta: (int) $result['minted'],
                progressCents: (int) $result['progress_cents'],
                reason: CarbonCreditType::Earned->value,
            );
        }

        $minted = (int) $result['minted'];

        if ($minted > 0) {
            $this->announce($userId, $before, $before + $minted);
        }

        return $minted;
    }

    /**
     * Tell the passenger when their balance crosses a milestone.
     *
     * Milestones only — 10, 20, 30 — because a push per credit is noise, and a
     * noisy app gets muted. The first credit is called out separately: it is the
     * moment the scheme becomes real to somebody, and the best chance to say
     * what it is for.
     *
     * referenceId is the milestone itself, so NotificationService's dedup makes
     * a replayed event or a re-credit harmless.
     */
    private function announce(int $userId, int $before, int $after): void
    {
        $user = User::withoutGlobalScopes()->find($userId);
        if ($user === null) {
            return;
        }

        if ($before === 0 && $after > 0) {
            $this->notifications->dispatch(
                $user,
                NotificationType::Promo,
                'Your first carbon credit',
                'You earned your first carbon credit just by travelling. Collect them for data bundles, free rides and shopping vouchers.',
                'carbon-first',
            );
        }

        $step = max(1, (int) config('carbon_credits.notify_every_credits', 10));
        if (intdiv($after, $step) <= intdiv($before, $step)) {
            return;
        }

        $milestone = intdiv($after, $step) * $step;

        $this->notifications->dispatch(
            $user,
            NotificationType::Promo,
            $milestone.' carbon credits',
            'You have '.$after.' carbon credits. See what you can claim.',
            'carbon-milestone-'.$milestone,
        );
    }

    /**
     * Claim a reward. Credits leave the balance now and the partner delivers
     * later; cancelling returns them.
     *
     * @return array{ok:bool, status?:int, error?:string, redemption?:CarbonCreditRedemption}
     */
    public function redeem(User $user, CarbonCreditReward $reward): array
    {
        if (! $this->enabled()) {
            return ['ok' => false, 'status' => 422, 'error' => 'Carbon credits are not available right now.'];
        }

        $result = DB::transaction(function () use ($user, $reward): array {
            // Re-read under lock: stock and the balance are both raced.
            $locked = CarbonCreditReward::whereKey($reward->id)->lockForUpdate()->first();
            if ($locked === null || ! $locked->isClaimable()) {
                return ['ok' => false, 'status' => 422, 'error' => 'That reward is no longer available.'];
            }

            $account = $this->lockedAccount((int) $user->id);
            if ($account->credits < $locked->credits_required) {
                return [
                    'ok' => false,
                    'status' => 422,
                    'error' => 'You need '.($locked->credits_required - $account->credits).' more carbon credits for this.',
                ];
            }

            $account->credits -= $locked->credits_required;
            $account->save();

            if ($locked->stock !== null) {
                $locked->decrement('stock');
            }

            CarbonCreditTransaction::create([
                'user_id' => $user->id,
                'credits' => -$locked->credits_required,
                'type' => CarbonCreditType::Redeemed,
                'spend_cents' => 0,
                'description' => 'Redeemed: '.$locked->name,
            ]);

            $redemption = CarbonCreditRedemption::create([
                'user_id' => $user->id,
                // Copied, so repricing the reward later cannot rewrite what this
                // passenger actually paid.
                'credits_spent' => $locked->credits_required,
                'carbon_credit_reward_id' => $locked->id,
                'status' => RedemptionStatus::Pending,
            ]);

            return ['ok' => true, 'redemption' => $redemption];
        });

        // After the commit, and never able to undo it: spending credits is the
        // one balance move the passenger is actively watching, and the claim
        // screen should not need a refetch to show the new number.
        if ($result['ok']) {
            $account = $this->accountFor((int) $user->id);
            PassengerBalanceChanged::carbon(
                userId: (int) $user->id,
                credits: (int) $account->credits,
                delta: -((int) $result['redemption']->credits_spent),
                progressCents: (int) $account->progress_cents,
                reason: CarbonCreditType::Redeemed->value,
            );
        }

        return $result;
    }

    /**
     * Mark a claim delivered, recording the partner's own reference.
     *
     * NO PassengerBalanceChanged HERE, DELIBERATELY. Fulfilment moves no
     * credits — they left the balance at redeem(), which is where the passenger
     * was told. A "your balance changed" carrying a zero delta would be a lie,
     * and the passenger already gets a real notification when the reward ships.
     *
     * THE TERMINAL-STATE GUARD IS INSIDE THE TRANSACTION AND UNDER A ROW LOCK.
     * It used to read `$redemption->status` off the in-memory model the
     * controller had already loaded, before any transaction opened — so two
     * operators working the same pending queue, or one double-clicking, both saw
     * Pending and both proceeded. On this path that is merely a duplicate push;
     * on cancel() it refunded the same credits twice and minted them from
     * nothing. Same shape of bug, so both are fixed the same way.
     */
    public function fulfil(CarbonCreditRedemption $redemption, ?string $reference = null): array
    {
        $result = DB::transaction(function () use ($redemption, $reference): array {
            $locked = CarbonCreditRedemption::whereKey($redemption->id)->lockForUpdate()->first();

            if ($locked === null) {
                return ['ok' => false, 'status' => 422, 'error' => 'That redemption no longer exists.'];
            }
            if ($locked->status !== RedemptionStatus::Pending) {
                return ['ok' => false, 'status' => 422, 'error' => 'That redemption is already '.$locked->status->value.'.'];
            }

            $locked->forceFill([
                'status' => RedemptionStatus::Fulfilled,
                'reference' => $reference,
                'fulfilled_at' => Carbon::now(),
            ])->save();

            return ['ok' => true, 'redemption' => $locked];
        });

        if (! $result['ok']) {
            return $result;
        }

        $redemption->forceFill([
            'status' => RedemptionStatus::Fulfilled,
            'reference' => $reference,
            'fulfilled_at' => $result['redemption']->fulfilled_at,
        ])->syncOriginal();

        // The claim was async; without this the passenger is left wondering
        // whether anything happened at all. Outside the transaction, so a push
        // failure cannot roll back a delivery that already happened.
        if ($user = User::withoutGlobalScopes()->find($redemption->user_id)) {
            $this->notifications->dispatch(
                $user,
                NotificationType::Promo,
                'Your reward is on its way',
                trim(($redemption->reward?->name ?? 'Your reward').' has been sent.'
                    .($reference !== null ? ' Reference: '.$reference.'.' : '')),
                'carbon-redemption-'.$redemption->id,
            );
        }

        return ['ok' => true, 'redemption' => $redemption];
    }

    /**
     * Cancel a claim and return the credits.
     *
     * THIS IS THE ONE THAT MINTED MONEY. The terminal-state guard read the
     * in-memory model the controller had loaded, outside and before the
     * transaction, and nothing locked the claim. Two operators working the same
     * pending queue — or one double-click, or a retried request — both saw
     * Pending, and both added credits_spent back to the balance and wrote a
     * Refunded row. Credits that were earned once came back twice, and the
     * account drifted above what the ledger could justify. The guard now sits
     * inside the transaction, under a row lock on the claim itself.
     *
     * LOCK ORDER: claim, then reward, then account — reward before account
     * matching redeem(), because the two used to take those in opposite orders
     * and could deadlock against each other on the same (account, reward) pair.
     */
    public function cancel(CarbonCreditRedemption $redemption, ?string $reason = null): array
    {
        $result = DB::transaction(function () use ($redemption, $reason): array {
            $locked = CarbonCreditRedemption::whereKey($redemption->id)->lockForUpdate()->first();

            if ($locked === null) {
                return ['ok' => false, 'status' => 422, 'error' => 'That redemption no longer exists.'];
            }
            if ($locked->status !== RedemptionStatus::Pending) {
                return ['ok' => false, 'status' => 422, 'error' => 'That redemption is already '.$locked->status->value.'.'];
            }

            // Return the stock too, or a cancelled claim quietly shrinks the
            // catalogue.
            $reward = CarbonCreditReward::whereKey($locked->carbon_credit_reward_id)->lockForUpdate()->first();
            if ($reward !== null && $reward->stock !== null) {
                $reward->increment('stock');
            }

            $account = $this->lockedAccount((int) $locked->user_id);
            $account->credits += $locked->credits_spent;
            $account->save();

            CarbonCreditTransaction::create([
                'user_id' => $locked->user_id,
                'credits' => $locked->credits_spent,
                'type' => CarbonCreditType::Refunded,
                'spend_cents' => 0,
                'description' => $reason ?? 'Redemption cancelled',
            ]);

            $locked->forceFill(['status' => RedemptionStatus::Cancelled])->save();

            return ['ok' => true, 'redemption' => $locked];
        });

        if (! $result['ok']) {
            return $result;
        }

        $redemption->forceFill(['status' => RedemptionStatus::Cancelled])->syncOriginal();

        // The refund lands on the balance, so the balance says so. Inside the
        // ok-guard and after the commit, which together are what stopped the
        // double-refund this method's docblock is about: a cancel that lost the
        // terminal-state race returns early above and broadcasts nothing.
        $account = $this->accountFor((int) $redemption->user_id);
        PassengerBalanceChanged::carbon(
            userId: (int) $redemption->user_id,
            credits: (int) $account->credits,
            delta: (int) $redemption->credits_spent,
            progressCents: (int) $account->progress_cents,
            reason: CarbonCreditType::Refunded->value,
        );

        // Credits are the passenger's to spend; never move them silently. Sent
        // after the commit, so a push failure cannot roll back the refund.
        if ($user = User::withoutGlobalScopes()->find($redemption->user_id)) {
            $this->notifications->dispatch(
                $user,
                NotificationType::Promo,
                'Reward cancelled',
                $redemption->credits_spent.' carbon credits have been returned to your balance.'
                    .($reason !== null ? ' '.$reason : ''),
                'carbon-cancelled-'.$redemption->id,
            );
        }

        return $result;
    }

    public function accountFor(int $userId): CarbonCreditAccount
    {
        // Defaults set explicitly, not left to the column defaults: firstOrCreate
        // returns the model it built, so a freshly created account would report
        // null credits until something reloaded it.
        return CarbonCreditAccount::firstOrCreate(
            ['user_id' => $userId],
            ['credits' => 0, 'progress_cents' => 0, 'lifetime_spend_cents' => 0],
        );
    }

    private function lockedAccount(int $userId): CarbonCreditAccount
    {
        $this->accountFor($userId);

        return CarbonCreditAccount::where('user_id', $userId)->lockForUpdate()->firstOrFail();
    }
}
