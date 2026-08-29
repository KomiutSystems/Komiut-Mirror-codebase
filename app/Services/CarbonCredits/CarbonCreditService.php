<?php

declare(strict_types=1);

namespace App\Services\CarbonCredits;

use App\Enums\CarbonCreditType;
use App\Enums\NotificationType;
use App\Enums\RedemptionStatus;
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

        $before = $this->accountFor((int) $booking->user_id)->credits;

        $minted = DB::transaction(function () use ($booking, $cents): int {
            // Lock the account for the whole read-modify-write: two payments
            // settling at once would otherwise both read the same remainder and
            // one would overwrite the other's progress.
            $account = $this->lockedAccount((int) $booking->user_id);

            // The partial unique index is the real guard — BookingPaid can fire
            // twice for one booking, and a re-credited ride is money. Checking
            // first turns that into a no-op instead of an exception.
            $already = CarbonCreditTransaction::where('booking_id', $booking->id)
                ->where('type', CarbonCreditType::Earned)
                ->exists();

            if ($already) {
                return 0;
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
                'user_id' => $booking->user_id,
                'credits' => $minted,
                'type' => CarbonCreditType::Earned,
                'spend_cents' => $cents,
                'booking_id' => $booking->id,
                'description' => $minted > 0
                    ? 'Earned '.$minted.' carbon credit'.($minted === 1 ? '' : 's')
                    : 'Travel counted toward your next credit',
            ]);

            return $minted;
        });

        // Outside the transaction on purpose: a push must never be able to roll
        // back a credit, and the balance has to be committed before we tell
        // somebody about it.
        if ($minted > 0) {
            $this->announce((int) $booking->user_id, $before, $before + $minted);
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

        return DB::transaction(function () use ($user, $reward): array {
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
    }

    /** Mark a claim delivered, recording the partner's own reference. */
    public function fulfil(CarbonCreditRedemption $redemption, ?string $reference = null): array
    {
        if ($redemption->status !== RedemptionStatus::Pending) {
            return ['ok' => false, 'status' => 422, 'error' => 'That redemption is already '.$redemption->status->value.'.'];
        }

        $redemption->forceFill([
            'status' => RedemptionStatus::Fulfilled,
            'reference' => $reference,
            'fulfilled_at' => Carbon::now(),
        ])->save();

        // The claim was async; without this the passenger is left wondering
        // whether anything happened at all.
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

    /** Cancel a claim and return the credits. */
    public function cancel(CarbonCreditRedemption $redemption, ?string $reason = null): array
    {
        if ($redemption->status !== RedemptionStatus::Pending) {
            return ['ok' => false, 'status' => 422, 'error' => 'That redemption is already '.$redemption->status->value.'.'];
        }

        return DB::transaction(function () use ($redemption, $reason): array {
            $account = $this->lockedAccount((int) $redemption->user_id);
            $account->credits += $redemption->credits_spent;
            $account->save();

            // Return the stock too, or a cancelled claim quietly shrinks the
            // catalogue.
            $reward = CarbonCreditReward::whereKey($redemption->carbon_credit_reward_id)->lockForUpdate()->first();
            if ($reward !== null && $reward->stock !== null) {
                $reward->increment('stock');
            }

            CarbonCreditTransaction::create([
                'user_id' => $redemption->user_id,
                'credits' => $redemption->credits_spent,
                'type' => CarbonCreditType::Refunded,
                'spend_cents' => 0,
                'description' => $reason ?? 'Redemption cancelled',
            ]);

            $redemption->forceFill(['status' => RedemptionStatus::Cancelled])->save();

            // Credits are the passenger's to spend; never move them silently.
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

            return ['ok' => true, 'redemption' => $redemption];
        });
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
