<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A passenger's reward balance moved — pushed to their own phone over Reverb.
 *
 * Two schemes move a passenger's balance and neither told anybody: SACCO loyalty
 * points (LoyaltyService) and platform carbon credits (CarbonCreditService). The
 * app's Activity screen had nothing to subscribe to, so a passenger who paid a
 * fare watched a stale number until they pulled to refresh.
 *
 * ---------------------------------------------------------------------------
 * AN EVENT, NOT A NOTIFICATION — and this is the load-bearing decision.
 *
 * NotificationService::dispatch() is the right choke point for something worth
 * telling somebody. It persists a row, pushes FCM and shows a badge, and that is
 * exactly wrong here: earning is per-fare, a matatu fare earns fractions of a
 * point, and nobody wants their phone to buzz for 0.6 points. The carbon scheme
 * already draws that line for itself — CarbonCreditService::announce() notifies
 * on milestones only, precisely because "a push per credit is noise, and a noisy
 * app gets muted". This event is the quieter half of the same idea: not "here is
 * news", just "your number moved, refetch". Nothing is persisted, nothing is
 * pushed, nothing is badged.
 *
 * It also sidesteps a trap the notification path has already sprung on us.
 * Laravel's BroadcastNotificationCreated::broadcastWith() does
 * `array_merge($this->data, ['id' => ..., 'type' => $this->broadcastType()])`,
 * and broadcastType() defaults to get_class($notification) — so `type`, the
 * field a client switches on, arrives over the socket as
 * "App\Notifications\PlatformNotification" while the REST copy of the same
 * notification says "trip". PlatformNotification has to override broadcastWith()
 * to take its own payload back (see the docblock there, and
 * BroadcastPayloadShapeTest). A ShouldBroadcast EVENT is not run through that
 * class at all: Laravel sends broadcastWith() verbatim, adding only `socket`, and
 * the wire name comes from broadcastAs(). Every key and value below is therefore
 * ours. BalanceBroadcastTest pins that — the discriminator is `scheme`, and no
 * field may ever hold a PHP class name.
 *
 * ---------------------------------------------------------------------------
 * IT MUST NEVER COST A PAYMENT.
 *
 * Earning runs inside the payment path. BookingPaid fires from Booking::updated,
 * which in the reconcile path runs INSIDE the settlement transaction, and both
 * EarnLoyaltyPoints and EarnCarbonCredits wrap the earn in a savepoint precisely
 * so a failure there cannot poison it. A throw from a broadcast would land inside
 * that savepoint and roll the POINTS back — the passenger would silently lose an
 * earn because a socket server was unreachable. C2bPaymentRecorder's class
 * docblock is the reason this is written down rather than assumed: 52 confirmed
 * payments once vanished when something threw after the money had already moved.
 *
 * So announce() is the only way this event is ever dispatched, and it:
 *   - is always called AFTER the balance write's own DB::transaction has
 *     returned, never from inside it;
 *   - catches Throwable and logs at error WITH the exception class. Logging at
 *     error and naming the class is deliberate: an earlier realtime attempt in
 *     this codebase logged a bare warning and hid its own cause through four red
 *     runs. A catch that hides why it caught is worse than no catch.
 *
 * Plain ShouldBroadcast, like VehicleMoved and PaymentRecorded beside it — NOT
 * ShouldBroadcastNow. Production runs QUEUE_CONNECTION=redis, so the actual
 * socket write happens on a worker and no part of it is on the payment's
 * critical path. (ShouldBroadcastAfterCommit does not exist in this framework
 * version; reaching for it is what got the first PaymentRecorded attempt
 * reverted. Nor is DB::afterCommit used here: Connection::afterCommit() THROWS
 * when no transactions manager is set, which would hand the payment path a brand
 * new way to fail for the sake of a nicety it does not need — see below.)
 *
 * ---------------------------------------------------------------------------
 * THIS IS AN ACCELERANT, NOT A SOURCE OF TRUTH. DO NOT REMOVE THE FETCH.
 *
 * Passengers ride matatus. They go through tunnels, they lose signal, their
 * phone sleeps, the app gets swapped out. A socket that is down or a frame that
 * never lands must cost the passenger nothing, so the client MUST keep fetching
 * its balances on screen open and on pull-to-refresh, and treat this event as an
 * optimisation on top: patch the number now, and let the next fetch be right.
 *
 * That contract is also what makes the ordering above safe. If this fires and an
 * outer settlement transaction is later rolled back, the worst case is a client
 * that refetches and reads the balance it already had — a wasted request, not a
 * wrong number. Nothing here is ever the only way a passenger learns anything.
 *
 * ---------------------------------------------------------------------------
 * `fulfil()` DELIBERATELY DOES NOT FIRE THIS. Marking a claim delivered moves no
 * credits — they left the balance at redeem(), which is where the passenger was
 * told. Broadcasting a zero-movement "your balance changed" would be a lie, and
 * the passenger already gets a real notification when their reward ships.
 */
final class PassengerBalanceChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /** The SACCO's own points, held per (passenger, SACCO). */
    public const SCHEME_LOYALTY = 'loyalty';

    /** The platform's carbon credits — one balance across every SACCO and brand. */
    public const SCHEME_CARBON = 'carbon';

    /** The name the mobile client binds to. Ours, never a PHP class name. */
    public const WIRE_NAME = 'balance.changed';

    /** ISO-8601, stamped at the moment the balance moved. */
    public readonly string $at;

    /**
     * Deliberately NOT SerializesModels: every property here is a scalar, so
     * PHP's own serialization carries this onto the queue intact. The trait
     * exists to swap Eloquent models for their keys, and there are none.
     *
     * @param  float|int  $balance  the balance AFTER the move (points are decimal, credits are whole)
     * @param  float|int  $delta  signed: positive earned/refunded, negative spent
     */
    public function __construct(
        public int $userId,
        public string $scheme,
        public float|int $balance,
        public float|int $delta,
        public string $reason,
        public ?int $saccoId = null,
        public ?int $progressCents = null,
    ) {
        $this->at = Carbon::now()->toIso8601String();
    }

    /**
     * Points moved on one SACCO's card.
     *
     * $reason is a LoyaltyTransactionType value (earned / redeemed / reversed /
     * refunded) — the same vocabulary the ledger and the history endpoint use,
     * so the client needs no second mapping.
     */
    public static function loyalty(int $userId, int $saccoId, float $balance, float $delta, string $reason): void
    {
        self::announce(new self(
            userId: $userId,
            scheme: self::SCHEME_LOYALTY,
            // Rounded to match what LoyaltyService::summary() returns over REST,
            // so a patched card and a fetched one never disagree in the last
            // decimal place.
            balance: round($balance, 2),
            delta: round($delta, 2),
            reason: $reason,
            saccoId: $saccoId,
        ));
    }

    /**
     * The platform carbon balance moved.
     *
     * $delta CAN BE ZERO and that is not a bug: a matatu fare is 30–150 KSh and
     * a credit is 1,000, so most paid rides mint nothing and only push the
     * accumulator along. The ledger records those rides for exactly that reason
     * ("otherwise a passenger cannot see why their balance moved"), the Activity
     * screen shows them, and `progressCents` is what lets the client redraw
     * "X KSh to your next credit" without a refetch. $reason is a
     * CarbonCreditType value.
     */
    public static function carbon(int $userId, int $credits, int $delta, int $progressCents, string $reason): void
    {
        self::announce(new self(
            userId: $userId,
            scheme: self::SCHEME_CARBON,
            balance: $credits,
            delta: $delta,
            reason: $reason,
            progressCents: $progressCents,
        ));
    }

    /**
     * The ONLY way this event is dispatched. See the class docblock: a realtime
     * nudge is worth far less than a fare, and must never become a new way to
     * lose one.
     */
    private static function announce(self $event): void
    {
        try {
            event($event);
        } catch (Throwable $e) {
            Log::error('balance broadcast failed', [
                'user_id' => $event->userId,
                'scheme' => $event->scheme,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Laravel's own notification channel, already registered in
     * routes/channels.php and authorised on `(int) $user->id === (int) $id` — a
     * passenger can subscribe to their own and to nothing else. Reusing it means
     * the app opens ONE private channel for balances and notifications together,
     * and no new authorisation surface is introduced.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        // A balance with no passenger behind it has nobody to tell. Cannot
        // happen through the two named constructors; cheap insurance if it ever
        // gains a third.
        if ($this->userId <= 0) {
            return [];
        }

        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return self::WIRE_NAME;
    }

    /**
     * camelCase, matching NotificationResource and the app's own model parsers,
     * so nothing on the client needs a transform layer for this one payload.
     *
     * Carries enough to patch the screen without a refetch: which scheme moved,
     * the balance after, the signed delta and when. `saccoId` says WHICH card
     * moved and is null for carbon, which is platform-wide by design.
     * `progressCents` is carbon-only and null for loyalty.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'scheme' => $this->scheme,
            'saccoId' => $this->saccoId,
            'balance' => $this->balance,
            'delta' => $this->delta,
            'reason' => $this->reason,
            'progressCents' => $this->progressCents,
            'at' => $this->at,
        ];
    }
}
