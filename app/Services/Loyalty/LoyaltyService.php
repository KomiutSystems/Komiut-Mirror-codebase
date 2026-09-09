<?php

declare(strict_types=1);

namespace App\Services\Loyalty;

use App\Enums\LoyaltyTransactionType;
use App\Enums\PaymentMethod;
use App\Events\PassengerBalanceChanged;
use App\Models\Booking;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyTransaction;
use App\Models\Sacco;
use App\Models\Scopes\BrandScope;
use App\Models\Scopes\SaccoScope;
use App\Models\User;
use App\Support\Phone;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

/**
 * Loyalty points: earn (proportional to fare, on a paid ride) and redeem (spend
 * the SACCO's threshold to settle a booking as a free ride). Balances are held
 * per (user, SACCO); the loyalty_transactions ledger is the audit trail and the
 * unique (booking_id, type) index makes both operations idempotent.
 */
class LoyaltyService
{
    /**
     * Credit earn points for a paid booking. Idempotent, and never earns on a
     * ride that was itself paid with points. Returns null when nothing was earned.
     */
    public function earnForBooking(Booking $booking): ?LoyaltyTransaction
    {
        if (! $booking->paid || $booking->user_id === null) {
            return null;
        }
        if ($this->hasType((int) $booking->id, LoyaltyTransactionType::Redeemed)) {
            return null; // free ride — no earning
        }

        $saccoId = $this->saccoIdForBooking($booking);
        if ($saccoId === null) {
            return null;
        }
        $program = $this->activeProgram($saccoId);
        if ($program === null || $program->divisor <= 0) {
            return null;
        }

        $points = round((float) $booking->amount / $program->divisor, 2);
        if ($points <= 0) {
            return null;
        }

        return $this->credit((int) $booking->user_id, $saccoId, $points, LoyaltyTransactionType::Earned, (int) $booking->id);
    }

    /**
     * Settle a RESERVED (unpaid) booking with points — the free ride. Returns a
     * result array: ['ok'=>bool, ...] with an 'error'/'status' when it can't.
     *
     * @return array<string, mixed>
     */
    public function redeemForBooking(User $user, Booking $booking): array
    {
        if ((int) $booking->user_id !== (int) $user->id) {
            return ['ok' => false, 'status' => 403, 'error' => 'This booking is not yours.'];
        }

        // A REPLAY IS A SUCCESS, NOT A CONFLICT.
        //
        // The app retries. It runs on a handset on a moving matatu, so the
        // request that settled the ride is exactly the one whose response is most
        // likely to be lost. Answering the retry with 422 "already paid" told the
        // passenger their free ride had FAILED when it had in fact worked, and
        // gave them the identical string they would get if the ride had settled
        // by M-Pesa -- so the client could not tell the two apart either.
        //
        // The old code intended this: debit() has a DEBIT_REPLAY outcome and the
        // docblock below promised "a replayed redeem finds the ledger row already
        // there and moves nothing". It was unreachable. `paid` and the ledger row
        // are written in the same transaction, and nothing in this codebase ever
        // sets bookings.paid back to false, so the paid guard above always fired
        // first and debit() was never asked. The promise was real; the ordering
        // defeated it. Checking the LEDGER rather than the flag is what makes it true.
        if ((bool) $booking->paid) {
            $spent = $this->redeemedValue((int) $booking->id);

            if ($spent !== null) {
                return ['ok' => true, 'booking' => $booking, 'points_spent' => $spent, 'moved' => false];
            }

            // Paid, but not by points -- M-Pesa, cash or a QR scan got there
            // first. That is a genuine conflict and the passenger keeps their points.
            return ['ok' => false, 'status' => 422, 'error' => 'This booking is already paid.'];
        }

        // AN EXPIRED BOOKING MUST NOT BE SETTLED. This is the guard whose absence
        // made the whole feature a way to lose points for nothing.
        //
        // CheckPassengerPayments cancels unpaid bookings and RELEASES THEIR SEATS
        // back on sale. Without this check every guard still passed on a cancelled
        // row: the passenger spent their points, was told "Free ride redeemed!",
        // the driver was pushed a seat-confirmed notification -- and the manifest
        // showed nobody, because the manifest keys on status. Verified against
        // production booking 1 on 2026-09-09, cancelled at 09:08 with seats 10 and
        // 11 already released, which every guard would still have accepted.
        //
        // Checked here AND re-read inside the transaction below, because the
        // Booking handed in was loaded unlocked by the controller and the sweep
        // runs on its own schedule.
        if (! (bool) $booking->status) {
            return ['ok' => false, 'status' => 422, 'error' => 'This reservation has expired. Please book again.'];
        }

        $saccoId = $this->saccoIdForBooking($booking);
        $program = $saccoId !== null ? $this->activeProgram($saccoId) : null;
        if ($program === null) {
            return ['ok' => false, 'status' => 422, 'error' => 'This SACCO has no active loyalty program.'];
        }
        $cost = (float) $program->redemption_threshold;
        if ($cost <= 0) {
            return ['ok' => false, 'status' => 422, 'error' => 'Point redemption is not available for this SACCO.'];
        }

        $result = DB::transaction(function () use ($user, $booking, $saccoId, $cost) {
            // Re-read under a lock. The guard above ran against a row the
            // controller loaded unlocked, and the expiry sweep runs every two
            // minutes on its own schedule -- so between that check and this write
            // the booking can be cancelled and its seats resold. Losing that race
            // costs a passenger real points for a seat somebody else now has.
            $fresh = Booking::withoutGlobalScopes()->lockForUpdate()->find($booking->id);

            if ($fresh === null || ! (bool) $fresh->status) {
                return ['ok' => false, 'status' => 422, 'error' => 'This reservation has expired. Please book again.'];
            }

            if ((bool) $fresh->paid && $this->redeemedValue((int) $booking->id) === null) {
                return ['ok' => false, 'status' => 422, 'error' => 'This booking is already paid.'];
            }

            $outcome = $this->debit((int) $user->id, $saccoId, $cost, (int) $booking->id);
            if ($outcome === self::DEBIT_INSUFFICIENT) {
                return ['ok' => false, 'status' => 422, 'error' => 'You do not have enough points for a free ride.'];
            }

            $booking->paid = true;
            $booking->payment_method = PaymentMethod::LoyaltyPoints;
            $booking->save();

            return [
                'ok' => true, 'booking' => $booking, 'points_spent' => $cost,
                'moved' => $outcome === self::DEBIT_DONE,
            ];
        });

        // AFTER the transaction, never inside it: a socket failure must not roll
        // back a redemption that already settled a ride. Only when the balance
        // genuinely moved — a replayed redeem finds the ledger row already there
        // and moves nothing, so there is nothing to announce.
        if (($result['moved'] ?? false) === true) {
            PassengerBalanceChanged::loyalty(
                userId: (int) $user->id,
                saccoId: $saccoId,
                balance: $this->balance((int) $user->id, $saccoId),
                delta: -$cost,
                reason: LoyaltyTransactionType::Redeemed->value,
            );
        }

        unset($result['moved']);

        return $result;
    }

    public function balance(int $userId, int $saccoId): float
    {
        return (float) (LoyaltyAccount::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->where('sacco_id', $saccoId)
            ->value('balance') ?? 0);
    }

    /**
     * Per-SACCO reward cards for the passenger's loyalty screen.
     *
     * DRIVEN BY PROGRAMS, NOT BY BALANCES. This used to read the passenger's
     * loyalty_accounts and return [] when there were none, which made the card a
     * receipt for points already earned rather than the thing that tells you the
     * scheme exists. A passenger who has never earned saw an empty screen, and
     * on 2026-09-07 that was every passenger on the platform: zero accounts,
     * zero transactions, because earning is booking-driven and there were no
     * bookings. The card has to come first — you cannot earn toward a reward
     * nobody showed you.
     *
     * So the list is every ACTIVE program, carrying the passenger's balance when
     * they have one and zero when they do not, UNION every SACCO they already
     * hold a balance with. That union matters: a SACCO that switches its program
     * off must not silently delete points people have already earned from their
     * screen — those keep their card, marked is_active false.
     *
     * BRAND still applies, so a Komiut passenger is never offered a SACCO that
     * runs no Komiut buses — but it is applied through the VEHICLES rather than
     * through saccos.brand, which is one column and therefore has no correct
     * value for NICCO, whose 180 buses split 126 komiut / 54 safiri.
     *
     * SACCOSCOPE is dropped deliberately: it fails closed on a NULL sacco_id,
     * and a passenger belongs to no SACCO by definition — the same reason Sacco
     * itself opts into cross-tenant browsing for the public directory. It is
     * dropped HERE, at the one call site that wants it, rather than on the
     * model, so the SACCO- and admin-facing loyalty endpoints keep the tenant
     * wall they rely on.
     *
     * @return array<int, array<string, mixed>>
     */
    public function summary(int $userId): array
    {
        $active = LoyaltyProgram::withoutGlobalScope(SaccoScope::class)
            ->withoutGlobalScope(BrandScope::class)
            ->where('is_active', true)
            // Brand-scoped through the VEHICLES, not through saccos.brand.
            //
            // BrandScope reaches brand via `sacco`, and saccos.brand is a single
            // column the schema itself calls the SACCO's PRIMARY brand and
            // explicitly not authoritative -- vehicle brand is. NICCO is why:
            // 126 of its buses are komiut and 54 are safiri, and it is the only
            // SACCO on the platform spanning two. No value of that one column is
            // correct for it. Set it to komiut and a 2Safiri passenger riding one
            // of those 54 buses is never shown the card; set it to safiri and
            // every Komiut passenger loses it.
            //
            // That passenger EARNS either way -- earnForFare resolves the
            // programme through activeProgram(), which drops all scopes -- so a
            // brand-scoped card list hides a scheme they are already accruing
            // points in. Exactly the bug this method exists to fix, leaking back
            // in through the cross-brand case.
            //
            // A raw subquery on `vehicles` rather than whereHas('sacco.vehicles'):
            // it needs no relation that does not exist yet, and it cannot pick up
            // Vehicle's own global scopes in a passenger request that has no SACCO.
            ->when(
                Context::has('brand'),
                fn ($q) => $q->whereIn('sacco_id', function ($sub) {
                    $sub->select('sacco_id')->from('vehicles')
                        ->where('brand', (string) Context::get('brand'))
                        ->whereNotNull('sacco_id');
                })
            )
            ->get()
            ->keyBy('sacco_id');

        $balances = LoyaltyAccount::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->pluck('balance', 'sacco_id');

        $saccoIds = $active->keys()->merge($balances->keys())->unique()->values();
        if ($saccoIds->isEmpty()) {
            return [];
        }

        // Held-but-inactive SACCOs need their program row too, for the threshold
        // to still read correctly on a card that is no longer earning.
        $programs = LoyaltyProgram::withoutGlobalScopes()
            ->whereIn('sacco_id', $saccoIds)
            ->get()
            ->keyBy('sacco_id');

        $saccoNames = Sacco::withoutGlobalScopes()->whereIn('id', $saccoIds)->pluck('name', 'id');

        return $saccoIds
            ->map(function ($saccoId) use ($active, $programs, $balances, $saccoNames) {
                $program = $programs->get($saccoId);
                $balance = (float) ($balances[$saccoId] ?? 0);
                $threshold = (float) ($program->redemption_threshold ?? 0);
                $isActive = $active->has($saccoId);

                return [
                    'sacco_id' => (int) $saccoId,
                    'sacco' => $saccoNames[$saccoId] ?? null,
                    'balance' => round($balance, 2),
                    'redemption_threshold' => $threshold,
                    'points_to_reward' => round(max(0, $threshold - $balance), 2),
                    'eligible_to_redeem' => $isActive && $threshold > 0 && $balance >= $threshold,
                    'is_active' => $isActive,
                ];
            })
            // Redeemable first, then where they already have points, then
            // closest to a reward. Name last so the order is stable across
            // requests rather than following whatever the DB returned.
            ->sortBy([
                ['eligible_to_redeem', 'desc'],
                ['balance', 'desc'],
                ['points_to_reward', 'asc'],
                ['sacco', 'asc'],
            ])
            ->values()
            ->all();
    }

    /**
     * Credit points for an IN-APP fare that is not a booking - today that means
     * a QR scan on the bus, and nothing else.
     *
     * READ THIS BEFORE ADDING A CALLER. This method was originally written to
     * close a different gap: earning fired only on a Booking flipping to paid,
     * so the rails carrying the money - C2B/till at ~98.6% of revenue, and QR -
     * earned nothing. It was wired to BOTH. The C2B call site was then removed
     * deliberately on 2026-09-08: rewards exist to move passengers onto the
     * app's own rails, and a direct till payment needs no app and proves no app
     * use. See the comment at the removal site in C2bPaymentRecorder::attempt.
     *
     * So one caller is correct, not half-finished. The consequence is deliberate
     * and worth stating plainly: a passenger who pays by till earns nothing, and
     * till is how most people pay. If that is ever revisited, the C2B path
     * already resolves a payer phone and passengerIdForPhone() already exists -
     * re-wiring is small. The decision is the hard part, not the code.
     *
     * IDEMPOTENT ON THE SOURCE, not on a booking that does not exist. The
     * (source_type, source_id, type) unique index is the guard, and the
     * constraint violation is caught rather than prevented: a check-then-insert
     * loses to a concurrent duplicate, and Safaricom can and does deliver the
     * same confirmation twice at once. Losing that race must return the credit
     * already written, not a second one.
     *
     * A FARE THAT PREDATES THE SCHEME EARNS NOTHING. `paidAt` is checked against
     * the program's start because this same recorder is the save chain for
     * payments:backfill-from-legacy, and the outstanding NCBA backfill alone is
     * 46,819 payments. Crediting those would retroactively mint points for rides
     * taken months before any SACCO agreed to a rewards scheme — a commercial
     * liability nobody signed up for, conjured by an import. Omit `paidAt` only
     * where the fare is known to be current.
     *
     * Returns null when nothing was earned - no active program, a zero divisor,
     * a fare that predates the program, or one too small to round to any points.
     */
    public function earnForFare(
        int $userId,
        int $saccoId,
        float $amount,
        string $sourceType,
        int $sourceId,
        ?CarbonInterface $paidAt = null,
    ): ?LoyaltyTransaction {
        if ($amount <= 0) {
            return null;
        }

        $program = $this->activeProgram($saccoId);
        if ($program === null || $program->divisor <= 0) {
            return null;
        }

        if ($paidAt !== null && $program->created_at !== null && $paidAt->lt($program->created_at)) {
            return null;
        }

        $points = round($amount / $program->divisor, 2);
        if ($points <= 0) {
            return null;
        }

        return $this->credit(
            $userId,
            $saccoId,
            $points,
            LoyaltyTransactionType::Earned,
            null,
            $sourceType,
            $sourceId,
        );
    }

    /**
     * The app account behind a payer's phone number, or null when the number
     * belongs to nobody we know.
     *
     * A till payment identifies its payer by MSISDN alone. Stored numbers are
     * not uniform - the app has always written the local `0712345678` form while
     * Safaricom sends `254712345678` - so a direct comparison silently matches
     * nothing for whichever half is stored the other way. Phone::lookupForms is
     * the existing answer to that and is what login and password reset already
     * use.
     *
     * WITHOUT GLOBAL SCOPES because this runs in a webhook with no authenticated
     * user, where SaccoScope would fail closed and match nobody at all.
     *
     * Lowest id wins if a number somehow appears twice: it is the older account,
     * and picking deterministically beats crediting a different one each time.
     */
    public function passengerIdForPhone(?string $phone): ?int
    {
        $forms = Phone::lookupForms($phone);
        if ($forms === []) {
            return null;
        }

        $id = User::withoutGlobalScopes()
            ->whereIn('phone', $forms)
            ->orderBy('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    // ---- internals ----

    private function credit(
        int $userId,
        int $saccoId,
        float $points,
        LoyaltyTransactionType $type,
        ?int $bookingId,
        ?string $sourceType = null,
        ?int $sourceId = null,
    ): ?LoyaltyTransaction {
        $result = DB::transaction(function () use ($userId, $saccoId, $points, $type, $bookingId, $sourceType, $sourceId) {
            $already = $this->existingEntry($type, $bookingId, $sourceType, $sourceId);
            if ($already !== null) {
                return ['tx' => $already, 'moved' => false];
            }

            try {
                $tx = LoyaltyTransaction::create([
                    'user_id' => $userId, 'sacco_id' => $saccoId, 'value' => $points,
                    'type' => $type, 'booking_id' => $bookingId,
                    'source_type' => $sourceType, 'source_id' => $sourceId,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Lost the race to a concurrent delivery of the same payment.
                // The other writer's credit stands; returning it is the whole
                // point of idempotency, and crediting again would be the bug.
                // The winner already announced it, so this one stays quiet.
                return ['tx' => $this->existingEntry($type, $bookingId, $sourceType, $sourceId), 'moved' => false];
            }

            $account = LoyaltyAccount::withoutGlobalScopes()
                ->firstOrCreate(['user_id' => $userId, 'sacco_id' => $saccoId], ['balance' => 0]);
            LoyaltyAccount::withoutGlobalScopes()->whereKey($account->id)->increment('balance', $points);

            return ['tx' => $tx, 'moved' => true];
        });

        // AFTER the transaction, and only when the balance actually moved.
        //
        // OUTSIDE is the whole point. This runs inside EarnLoyaltyPoints'
        // savepoint, which itself can be inside the settlement transaction — a
        // throw from in there would roll the points back and the passenger would
        // silently lose an earn because a socket server was unreachable.
        // PassengerBalanceChanged::announce() swallows its own failures too; both
        // guards are deliberate, and the event's docblock says why.
        //
        // The balance is re-read rather than computed as before+points: another
        // writer may have credited the same card in between, and the number on
        // the wire has to be the one the next fetch will agree with.
        if ($result['moved']) {
            PassengerBalanceChanged::loyalty(
                userId: $userId,
                saccoId: $saccoId,
                balance: $this->balance($userId, $saccoId),
                delta: $points,
                reason: $type->value,
            );
        }

        return $result['tx'];
    }

    /** The balance moved. */
    private const DEBIT_DONE = 'debited';

    /** A redemption already on the ledger for this booking — nothing moved. */
    private const DEBIT_REPLAY = 'replayed';

    /** Not enough points. */
    private const DEBIT_INSUFFICIENT = 'insufficient';

    /**
     * Atomic guarded decrement — the double-spend guard is the DB predicate.
     *
     * Returns WHICH of the three outcomes happened rather than a bare bool,
     * because the caller has to tell "spent" apart from "already spent": both
     * let the redemption succeed, but only one moved a balance worth
     * broadcasting.
     */
    private function debit(int $userId, int $saccoId, float $cost, ?int $bookingId): string
    {
        if ($bookingId !== null && $this->hasType($bookingId, LoyaltyTransactionType::Redeemed)) {
            return self::DEBIT_REPLAY; // already redeemed for this booking
        }

        $affected = LoyaltyAccount::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->where('sacco_id', $saccoId)
            ->where('balance', '>=', $cost)
            ->decrement('balance', $cost);

        if ($affected === 0) {
            return self::DEBIT_INSUFFICIENT;
        }

        LoyaltyTransaction::create([
            'user_id' => $userId, 'sacco_id' => $saccoId, 'value' => -$cost,
            'type' => LoyaltyTransactionType::Redeemed, 'booking_id' => $bookingId,
        ]);

        return self::DEBIT_DONE;
    }

    /**
     * The credit already written for this fare, under whichever key identifies
     * it - a booking, or a payment source. Null when there is none, or when the
     * caller supplied neither key (a manual adjustment, never deduped).
     */
    private function existingEntry(LoyaltyTransactionType $type, ?int $bookingId, ?string $sourceType, ?int $sourceId): ?LoyaltyTransaction
    {
        if ($bookingId !== null) {
            return LoyaltyTransaction::withoutGlobalScopes()
                ->where('booking_id', $bookingId)->where('type', $type->value)->first();
        }

        if ($sourceType !== null && $sourceId !== null) {
            return LoyaltyTransaction::withoutGlobalScopes()
                ->where('source_type', $sourceType)->where('source_id', $sourceId)
                ->where('type', $type->value)->first();
        }

        return null;
    }

    /**
     * What a booking's redemption actually cost, or null if it was never redeemed.
     *
     * Read from the LEDGER rather than recomputed from the program's current
     * threshold: a SACCO may change its threshold between the redemption and the
     * retry, and the honest answer to "what did I spend" is what was written down
     * at the time, not what it would cost today.
     */
    private function redeemedValue(int $bookingId): ?float
    {
        $row = LoyaltyTransaction::withoutGlobalScopes()
            ->where('booking_id', $bookingId)
            ->where('type', LoyaltyTransactionType::Redeemed->value)
            ->first();

        return $row === null ? null : abs((float) $row->value);
    }

    private function hasType(int $bookingId, LoyaltyTransactionType $type): bool
    {
        return LoyaltyTransaction::withoutGlobalScopes()
            ->where('booking_id', $bookingId)
            ->where('type', $type->value)
            ->exists();
    }

    private function activeProgram(int $saccoId): ?LoyaltyProgram
    {
        return LoyaltyProgram::withoutGlobalScopes()
            ->where('sacco_id', $saccoId)
            ->where('is_active', true)
            ->first();
    }

    private function saccoIdForBooking(Booking $booking): ?int
    {
        $booking->loadMissing('queue.vehicle');

        return $booking->queue?->vehicle?->sacco_id !== null
            ? (int) $booking->queue->vehicle->sacco_id
            : null;
    }
}
