<?php

declare(strict_types=1);

namespace App\Services\Loyalty;

use App\Enums\LoyaltyTransactionType;
use App\Enums\PaymentMethod;
use App\Enums\UserType;
use App\Events\FarePaidWithPoints;
use App\Events\PassengerBalanceChanged;
use App\Models\Booking;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyTransaction;
use App\Models\QrcodePayment;
use App\Models\Queue;
use App\Models\Sacco;
use App\Models\Scopes\BrandScope;
use App\Models\Scopes\SaccoScope;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Booking\BookingCancellation;
use App\Support\Phone;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Loyalty points: earn (proportional to fare, on a paid ride) and redeem (pay a
 * fare in points at the SACCO's point_value -- a ride costs what it costs, in
 * points as in shillings; see LoyaltyProgram::pointsFor). Balances are held
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

        $saccoId = $this->saccoIdForBooking($booking);
        $program = $saccoId !== null ? $this->activeProgram($saccoId) : null;
        if ($program === null) {
            return ['ok' => false, 'status' => 422, 'error' => 'This SACCO has no active loyalty program.'];
        }

        // THE RIDE COSTS WHAT IT COSTS. bookings.amount is the booking's whole
        // fare -- per-seat fare x seats, set server-side at creation -- and the
        // price in points is that fare at the SACCO's point_value. It used to be
        // the flat redemption_threshold however long the trip or however many
        // seats: "enough points for a ride" meant enough for ANY ride, and 50
        // points at a threshold-5 SACCO were ten bookings of unbounded length
        // and seat count. Found on the first real booking, 2026-09-12.
        $cost = $program->pointsFor((float) $booking->amount);
        if ($cost === null) {
            return ['ok' => false, 'status' => 422, 'error' => 'Point redemption is not set up for this SACCO yet.'];
        }
        if ($cost <= 0) {
            return ['ok' => false, 'status' => 422, 'error' => 'This booking has no fare to pay with points.'];
        }

        $result = DB::transaction(function () use ($user, $booking, $saccoId, $cost) {
            // AN EXPIRED RESERVATION MUST NOT BE SETTLED, and a locked re-read
            // is the only place that can honestly tell.
            //
            // CheckPassengerPayments cancels unpaid bookings and RELEASES THEIR
            // SEATS back on sale. Without this, every guard passed on a cancelled
            // row: the passenger spent their points, was told "Free ride
            // redeemed!", the driver got a seat-confirmed push -- and the manifest
            // showed nobody, because the manifest keys on status. Verified against
            // production booking 1 on 2026-09-09, cancelled at 09:08 with seats 10
            // and 11 already resold.
            //
            // CHECKED HERE AND NOWHERE ELSE, deliberately. An earlier draft also
            // checked before the transaction against the Booking the caller handed
            // in, and that attribute is not always loaded: `status` defaults to
            // true in the DATABASE, but Model::create() does not re-read the row,
            // so a freshly created booking carries no status in memory and
            // (bool) null called it expired. A locked SELECT is the only read that
            // is both complete and current -- and it is what closes the race
            // anyway, since the sweep runs on its own schedule and any check
            // outside this lock can be stale before the write lands.
            $fresh = Booking::withoutGlobalScopes()->lockForUpdate()->find($booking->id);

            if ($fresh === null || ! (bool) $fresh->status) {
                return ['ok' => false, 'status' => 422, 'error' => 'This reservation has expired. Please book again.'];
            }

            if ((bool) $fresh->paid && $this->redeemedValue((int) $booking->id) === null) {
                return ['ok' => false, 'status' => 422, 'error' => 'This booking is already paid.'];
            }

            // A DEAD TRIP CANNOT BE PAID FOR. The queue's status is read here,
            // under the same lock, because the crew can end the trip between
            // the passenger opening the sheet and tapping pay. Points spent on
            // a Completed or Cancelled trip bought a ride nobody would take and
            // nobody would ever no-show -- the one settlement path for a paid,
            // unboarded booking is the crew's, and the crew has gone home.
            if (BookingCancellation::isTripOver(Queue::withoutGlobalScopes()->find($fresh->queue_id))) {
                return ['ok' => false, 'status' => 422, 'error' => 'This trip has ended. Please book another.'];
            }

            $outcome = $this->debit((int) $user->id, $saccoId, $cost, (int) $booking->id);
            if ($outcome === self::DEBIT_INSUFFICIENT) {
                return [
                    'ok' => false, 'status' => 422,
                    'error' => $this->notEnough($cost, $this->balance((int) $user->id, $saccoId)),
                    'points_needed' => $cost,
                ];
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
                ] + $this->worth($program, $balance);
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

    /**
     * How long a QR points scan stays the SAME boarding.
     *
     * A repeat inside this window returns the first redemption rather than
     * taking more points. The printed QR is static, so nothing in the request
     * can tell a retry from a new ride, and on a matatu the lost response is
     * the common case. Ten minutes covers any retry a handset will make and is
     * far shorter than boarding the same bus a second time.
     */
    private const SCAN_REPLAY_MINUTES = 10;

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
    private function debit(
        int $userId,
        int $saccoId,
        float $cost,
        ?int $bookingId,
        ?string $sourceType = null,
        ?int $sourceId = null,
    ): string {
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
            // A spend that is NOT a booking is keyed on its source instead --
            // today that is a QR scan, whose qrcode_payments row is the receipt.
            // loyalty_transactions_source_unique(source_type, source_id, type)
            // then makes the write idempotent for free, the same way
            // (booking_id, type) does for a booking.
            'source_type' => $sourceType,
            'source_id' => $sourceId,
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

    /**
     * Give a passenger back what they paid for a ride they did not get.
     *
     * THE POLICY, decided 2026-09-11: a passenger the crew marks NOT BOARDED is
     * refunded, always. There is no "they were late, tough" branch. What comes
     * back depends on what went in:
     *
     *   - PAID WITH POINTS: the exact points spent, read back from the ledger --
     *     not recomputed from a threshold that may have changed since.
     *   - PAID WITH MONEY: the fare's worth in points -- bookings.amount at the
     *     SACCO's point_value, exactly what redeem would have charged for this
     *     booking -- and the earn that payment minted is taken back. A B2C
     *     M-Pesa refund is a separate Daraja integration (initiator
     *     credentials, security credential, result URLs, reconciliation) and is
     *     NOT what this does. Until that exists, this is the only refund a
     *     money-paid passenger gets, and it is worth saying plainly: KES 150 by
     *     M-Pesa comes back as KES 150 of travel on THIS SACCO, not as KES 150.
     *   - NOT PAID: nothing to refund. Null.
     *
     * WHAT WENT IN IS WHAT COMES OUT. Two earlier shapes both got this wrong by
     * being detached from the fare. `passengers x threshold` (until 2026-09-11)
     * handed four seats paid KES 150 back as four flat rides of any size. The
     * flat threshold that replaced it refunded a KES 60 hop and a KES 400 trip
     * the same -- and once redeem priced rides by fare (2026-09-12) it would
     * have refunded a KES 400 trip as 150 shillings' worth. Reading the fare
     * through the same pointsFor() redeem uses is what keeps the two rails equal.
     *
     * THE EARN IS REVERSED, because the ride it was earned on never happened.
     * Paying KES 150 minted Earned +1.5; a refund on top of that added
     * Refunded +5 and left 6.5 points for a ride never taken, farmable by
     * booking and not boarding. So a money refund also writes a Reversed row
     * for the Earned value, keyed (booking_id, 'reversed') and therefore
     * idempotent under the same unique index, and the balance moves by
     * threshold - earned. NOT on a points refund: nothing was earned on a free
     * ride (earnForBooking refuses a redeemed booking). The decrement is not
     * clamped at zero -- a passenger who already spent an earn that is now
     * reversed goes negative, and that is the honest figure: the ledger and
     * the balance must keep agreeing, and debit() refuses anything below cost.
     *
     * ONLY A PASSENGER IS REFUNDED. Both booking-creation paths set user_id to
     * the authenticated caller, so a booking a CONDUCTOR keys in for a walk-in
     * carries the conductor's id -- and until 2026-09-11 a cash-paid walk-in
     * who never boarded refunded points to the conductor's own account. A
     * booking whose user is not a passenger refunds nothing, logged at info so
     * the walk-in case stays findable if a refund path for it ever exists.
     *
     * IDEMPOTENT BY CONSTRUCTION, AND THE INSERT IS THE GATE. The ledger row
     * is keyed on (booking_id, 'refunded') and
     * loyalty_transactions_booking_id_type_unique refuses a second one, so a
     * conductor tapping twice, or a retried request, cannot pay a passenger
     * back twice. A replay returns the existing row.
     *
     * The row is written FIRST, under its own savepoint, and the balance moves
     * only once that insert has succeeded. That order is load-bearing on
     * Postgres: a failed statement aborts the transaction until a rollback
     * (SQLSTATE 25P02 -- the rule EarnLoyaltyPoints documents), so the earlier
     * shape -- increment, insert, "undo" the increment in the catch -- meant
     * the undo itself threw, the exception escaped the closure, and the loser
     * of a race got an error instead of the winner's row. Rolling back to the
     * savepoint is what leaves the transaction usable inside the catch at all.
     *
     * The balance broadcast fires AFTER the transaction, never inside it -- a
     * socket failure must not roll back a refund that has already been written
     * -- and only from the call that WROTE the row. The race loser returns the
     * same row, and announcing from there put the refund on the passenger's
     * socket twice.
     */
    public function refundForBooking(Booking $booking): ?LoyaltyTransaction
    {
        if (! (bool) $booking->paid || $booking->user_id === null) {
            return null;
        }

        $userId = (int) $booking->user_id;
        $bookingId = (int) $booking->id;

        $user = User::withoutGlobalScopes()->find($userId);
        if ($user === null || $user->type !== UserType::Passenger) {
            Log::info('loyalty refund skipped: booking user is not a passenger', [
                'booking_id' => $bookingId,
                'user_id' => $userId,
                'type' => $user?->type?->value,
            ]);

            return null;
        }

        $existing = $this->existingEntry(LoyaltyTransactionType::Refunded, $bookingId, null, null);
        if ($existing !== null) {
            return $existing; // already refunded -- a replay, not a second refund
        }

        $saccoId = $this->saccoIdForBooking($booking);
        if ($saccoId === null) {
            return null;
        }

        $spent = $this->redeemedValue($bookingId);
        $earned = 0.0;

        if ($spent !== null) {
            $points = $spent;
        } else {
            // Money was paid. The fare's worth at THIS SACCO's point value. Read
            // the program even if inactive: a passenger whose SACCO switched
            // loyalty off between paying and being stranded is still owed
            // their ride.
            $program = LoyaltyProgram::withoutGlobalScopes()->where('sacco_id', $saccoId)->first();
            $points = $program?->pointsFor((float) $booking->amount);

            if ($points === null || $points <= 0) {
                return null; // no rate to convert at; nothing sensible to credit
            }

            $earned = $this->earnedValue($bookingId);
        }

        $result = DB::transaction(function () use ($userId, $saccoId, $points, $earned, $bookingId) {
            $refund = $this->insertLedgerRow([
                'user_id' => $userId, 'sacco_id' => $saccoId, 'value' => $points,
                'type' => LoyaltyTransactionType::Refunded, 'booking_id' => $bookingId,
            ]);

            if ($refund === null) {
                // Lost a race with a concurrent refund of the same booking. The
                // other writer's row is the truth and it moved the balance;
                // this call moves nothing and announces nothing.
                return [
                    'row' => $this->existingEntry(LoyaltyTransactionType::Refunded, $bookingId, null, null),
                    'moved' => false,
                    'delta' => 0.0,
                ];
            }

            $account = LoyaltyAccount::withoutGlobalScopes()->firstOrCreate(
                ['user_id' => $userId, 'sacco_id' => $saccoId],
                ['balance' => 0],
            );
            LoyaltyAccount::withoutGlobalScopes()->whereKey($account->id)->increment('balance', $points);
            $delta = $points;

            if ($earned > 0) {
                $reversal = $this->insertLedgerRow([
                    'user_id' => $userId, 'sacco_id' => $saccoId, 'value' => -$earned,
                    'type' => LoyaltyTransactionType::Reversed, 'booking_id' => $bookingId,
                ]);

                // Null means the earn is already reversed. Nothing else writes
                // Reversed today; the unique index is the guard, not that fact.
                if ($reversal !== null) {
                    LoyaltyAccount::withoutGlobalScopes()->whereKey($account->id)->decrement('balance', $earned);
                    $delta -= $earned;
                }
            }

            return ['row' => $refund, 'moved' => true, 'delta' => $delta];
        });

        if ($result['moved']) {
            // ONE frame for the net move, not one per ledger row: each frame
            // carries the balance AFTER the move, and two frames sharing one
            // final balance would each contradict the other's delta.
            PassengerBalanceChanged::loyalty(
                userId: $userId,
                saccoId: $saccoId,
                balance: $this->balance($userId, $saccoId),
                delta: $result['delta'],
                reason: LoyaltyTransactionType::Refunded->value,
            );
        }

        return $result['row'];
    }

    /**
     * Write one ledger row under its own savepoint, or return null when its
     * idempotency key -- (booking_id, type) or (source_type, source_id, type)
     * -- is already taken.
     *
     * A SAVEPOINT, NOT A BARE TRY/CATCH, because of how Postgres treats a
     * failed statement: the transaction is aborted until a rollback, and every
     * query after the failure -- the re-read of the winning row, a
     * compensating update -- fails with SQLSTATE 25P02. Nesting
     * DB::transaction() rolls the failed insert back to the savepoint alone,
     * and the caller's transaction carries on as if the statement had never
     * run. Outside any transaction it is simply a one-statement transaction.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function insertLedgerRow(array $attributes): ?LoyaltyTransaction
    {
        try {
            return DB::transaction(fn () => LoyaltyTransaction::create($attributes));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /** What a booking's payment earned, read from the ledger; zero when nothing was. */
    private function earnedValue(int $bookingId): float
    {
        $row = $this->existingEntry(LoyaltyTransactionType::Earned, $bookingId, null, null);

        return $row === null ? 0.0 : (float) $row->value;
    }

    /**
     * One SACCO's loyalty card for one passenger — the shape `loyalty/summary`
     * returns per row, for a single SACCO.
     *
     * Exists because the QR scan screen has to decide whether to OFFER "pay with
     * points" for the bus in front of the passenger, and it only knows a vehicle.
     * Calling summary() and filtering would build every card to use one.
     *
     * @return array{sacco_id: int, balance: float, redemption_threshold: float, points_to_reward: float, eligible_to_redeem: bool, is_active: bool, point_value: ?float, balance_value: ?float}
     */
    public function cardForSacco(int $userId, int $saccoId): array
    {
        $program = $this->activeProgram($saccoId);
        $balance = $this->balance($userId, $saccoId);
        $threshold = $program === null ? 0.0 : (float) $program->redemption_threshold;

        return [
            'sacco_id' => $saccoId,
            'balance' => $balance,
            'redemption_threshold' => $threshold,
            'points_to_reward' => max(0.0, $threshold - $balance),
            'eligible_to_redeem' => $program !== null && $threshold > 0 && $balance >= $threshold,
            'is_active' => $program !== null,
        ] + $this->worth($program, $balance);
    }

    /**
     * The two figures that let an app show points as money: what one point pays
     * for, and what this balance would pay for. Null on a program that has not
     * set a value -- the card still renders; "pay with points" does not.
     *
     * @return array{point_value: ?float, balance_value: ?float}
     */
    private function worth(?LoyaltyProgram $program, float $balance): array
    {
        if ($program === null || ! $program->canPriceRides()) {
            return ['point_value' => null, 'balance_value' => null];
        }

        return [
            'point_value' => (float) $program->point_value,
            'balance_value' => $program->kesFor($balance),
        ];
    }

    /** The refusal a passenger reads when the ride costs more than they hold. */
    private function notEnough(float $cost, float $balance): string
    {
        return sprintf(
            'This ride costs %s points and you have %s.',
            rtrim(rtrim(number_format($cost, 2, '.', ''), '0'), '.'),
            rtrim(rtrim(number_format(max(0.0, $balance), 2, '.', ''), '0'), '.'),
        );
    }

    /**
     * Pay for a ride with points by SCANNING THE BUS — no queue, no booking.
     *
     * WHY THIS EXISTS SEPARATELY FROM redeemForBooking. Points are a payment
     * method, and a payment method should not care whether the vehicle happens to
     * be sitting in a queue. redeemForBooking cannot serve this case even in
     * principle: it needs a Booking, `bookings.queue_id` is NOT NULL, and it
     * derives the SACCO as booking -> queue -> vehicle -> sacco_id. So a bus that
     * has left the stage and is on the road could not be paid for with points at
     * all — the opposite of how every other rail behaves, since a passenger can
     * always M-Pesa a till.
     *
     * Here the SACCO comes straight off the VEHICLE, which is the only thing a QR
     * scan actually identifies, and the receipt is a `qrcode_payments` row — the
     * same artefact an M-Pesa QR payment writes, so everything already reading
     * that table keeps working.
     *
     * IDEMPOTENCY IS BY RECENT RECEIPT, NOT BY TOKEN. The QR a passenger scans is
     * static and never expires: it is printed and stuck inside the matatu, so
     * every passenger on that bus scans the same string forever and nothing in the
     * request can identify one attempt. A repeat inside SCAN_REPLAY_MINUTES
     * therefore returns the FIRST redemption instead of taking more points — on a
     * moving matatu the lost response is the common case, and boarding the same
     * bus twice inside ten minutes is not a thing that happens. Beyond the window
     * it is a new ride and costs again, which is correct.
     *
     * THE REPLAY CHECK IS ONLY SAFE UNDER A LOCK. It is check-then-act: it looks
     * for a receipt, and the receipt it would find is created inside the
     * transaction that follows. Two scans in flight at once -- a double-tap, or
     * a retry racing the request it retries -- both found nothing, both wrote a
     * receipt, both debited: balance 10, threshold 5, two frames of the same
     * finger, and the passenger had 0 points, two receipts and two Redeemed rows
     * for one ride. The passenger's ACCOUNT ROW is the thing that exists before
     * either request writes anything, so it is what they serialise on: the
     * transaction takes SELECT ... FOR UPDATE on it first, and repeats the check
     * while holding it. The second request waits on the lock until the first has
     * committed, then finds the first's receipt and returns it as the replay it
     * is. The unlocked check before the transaction is kept as a fast path for
     * the plain retry -- it is not the guard.
     *
     * THE FARE IS NAMED BY THE PASSENGER, as it is for an M-Pesa QR payment: a
     * scan identifies a bus, not a journey, so there is no route or stop to
     * price from and the passenger says what the conductor asked for. The
     * points cost is that fare at the SACCO's point_value -- never the flat
     * threshold it once was, which made one scan worth any journey.
     *
     * @param  float  $fareKes  what the ride costs in shillings, as the passenger was told
     * @return array{ok: bool, status?: int, error?: string, points_spent?: float, fare?: float, payment?: QrcodePayment, balance?: float, replay?: bool}
     */
    public function redeemForVehicle(User $user, Vehicle $vehicle, float $fareKes, ?int $seatArrangementId = null): array
    {
        $saccoId = $vehicle->sacco_id === null ? null : (int) $vehicle->sacco_id;

        if ($saccoId === null) {
            return ['ok' => false, 'status' => 422, 'error' => 'This vehicle does not belong to a SACCO.'];
        }

        $program = $this->activeProgram($saccoId);
        if ($program === null) {
            return ['ok' => false, 'status' => 422, 'error' => 'This SACCO has no active loyalty program.'];
        }

        $cost = $program->pointsFor($fareKes);
        if ($cost === null) {
            return ['ok' => false, 'status' => 422, 'error' => 'Point redemption is not set up for this SACCO yet.'];
        }
        if ($cost <= 0) {
            return ['ok' => false, 'status' => 422, 'error' => 'Enter the fare to pay with points.'];
        }

        // The fast path: a plain retry never needs the lock. NOT the guard --
        // that is the same check repeated under the account row lock below.
        $recent = $this->recentScanRedemption((int) $user->id, (int) $vehicle->id);
        if ($recent !== null) {
            return $this->scanReplay((int) $user->id, $saccoId, $cost, $recent);
        }

        try {
            $result = DB::transaction(function () use ($user, $vehicle, $saccoId, $cost, $fareKes, $seatArrangementId) {
                // THE CONCURRENCY GUARD IS THIS ROW LOCK. Everything that
                // follows -- the replay check, the receipt, the debit -- runs
                // with the passenger's account row held, so a second scan for
                // the same passenger blocks here until this one has committed
                // and then sees its receipt. The docblock above has the race.
                $this->lockAccount((int) $user->id, $saccoId);

                $recent = $this->recentScanRedemption((int) $user->id, (int) $vehicle->id);
                if ($recent !== null) {
                    return ['replay' => $recent];
                }

                // The receipt is written FIRST so the ledger row can key on its id. If
                // the debit then fails, the whole transaction rolls back and no orphan
                // receipt survives.
                $payment = QrcodePayment::create([
                    'vehicle_id' => $vehicle->id,
                    'seat_arrangement_id' => $seatArrangementId,
                    'user_id' => $user->id,
                    // ZERO SHILLINGS, and that is the honest number: the passenger paid
                    // no money. What it cost is points, recorded on the loyalty ledger
                    // where they belong. A points figure in a KES column would misreport
                    // the SACCO's takings.
                    'amount' => 0,
                    'status' => true,
                ]);

                $outcome = $this->debit(
                    (int) $user->id, $saccoId, $cost, null, 'qrcode_payment', (int) $payment->id,
                );

                if ($outcome === self::DEBIT_INSUFFICIENT) {
                    // THROW, do not return. DB::transaction() commits whenever the
                    // closure returns normally -- returning an error array here left
                    // the receipt written above behind, an orphan row claiming a ride
                    // had been paid for that nobody paid for. Only an exception rolls
                    // it back. Caught immediately below and turned into the 422.
                    throw new InsufficientPointsException;
                }

                return ['ok' => true, 'payment' => $payment, 'points_spent' => $cost, 'fare' => $fareKes];
            });
        } catch (InsufficientPointsException) {
            return [
                'ok' => false, 'status' => 422,
                'error' => $this->notEnough($cost, $this->balance((int) $user->id, $saccoId)),
                'points_needed' => $cost,
            ];
        }

        if (isset($result['replay'])) {
            // Beaten to it while waiting on the lock: that is the other
            // request's ride, and that request announced it. Nothing moved here.
            return $this->scanReplay((int) $user->id, $saccoId, $cost, $result['replay']);
        }

        // After the transaction, never inside it: a socket failure must not roll
        // back a ride that has already been paid for.
        if (($result['ok'] ?? false) === true) {
            $result['balance'] = $this->balance((int) $user->id, $saccoId);

            PassengerBalanceChanged::loyalty(
                userId: (int) $user->id,
                saccoId: $saccoId,
                balance: $result['balance'],
                delta: -$cost,
                reason: LoyaltyTransactionType::Redeemed->value,
            );

            // And the CREW. A points fare leaves nothing at the door -- no
            // cash, no SMS, no till confirmation -- so until this the
            // conductor had only the passenger's word. Wrapped like every
            // other announce: a socket failure must not fail a paid ride.
            try {
                FarePaidWithPoints::dispatch($result['payment'], $fareKes, $cost, $user->firstname ?? null);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $result;
    }

    /**
     * The response for a scan that is the SAME boarding as a receipt already
     * written: the first redemption, restated. Nothing moves and nothing is
     * announced -- whichever request wrote that receipt did both.
     *
     * @return array{ok: bool, points_spent: float, payment: QrcodePayment, balance: float, replay: bool}
     */
    private function scanReplay(int $userId, int $saccoId, float $cost, QrcodePayment $recent): array
    {
        return [
            'ok' => true,
            'points_spent' => $this->redeemedValueForSource('qrcode_payment', (int) $recent->id) ?? $cost,
            'payment' => $recent,
            'balance' => $this->balance($userId, $saccoId),
            'replay' => true,
        ];
    }

    /**
     * Hold this passenger's account row on this SACCO for the rest of the
     * current transaction, creating it first if they have never held points
     * here. It is the row redeemForVehicle serialises concurrent scans on.
     *
     * A passenger with no row has no points and is about to be refused, so
     * nothing is racing for it yet -- it is created anyway so that there is
     * exactly one path through here and nothing below it ever runs unlocked.
     * firstOrCreate is savepoint-safe inside a transaction (Eloquent's
     * createOrFirst), so two first-ever scans racing to create the same row do
     * not poison the transaction of the one that loses: it finds the other's
     * row and locks that. A refused scan rolls the new row back with
     * everything else.
     */
    private function lockAccount(int $userId, int $saccoId): LoyaltyAccount
    {
        LoyaltyAccount::withoutGlobalScopes()->firstOrCreate(
            ['user_id' => $userId, 'sacco_id' => $saccoId],
            ['balance' => 0],
        );

        return LoyaltyAccount::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->where('sacco_id', $saccoId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * This passenger's own points-paid scan on this vehicle, if it is recent
     * enough to be the same boarding rather than a new one.
     */
    private function recentScanRedemption(int $userId, int $vehicleId): ?QrcodePayment
    {
        return QrcodePayment::where('user_id', $userId)
            ->where('vehicle_id', $vehicleId)
            ->where('created_at', '>=', now()->subMinutes(self::SCAN_REPLAY_MINUTES))
            ->whereIn('id', LoyaltyTransaction::withoutGlobalScopes()
                ->where('source_type', 'qrcode_payment')
                ->where('type', LoyaltyTransactionType::Redeemed->value)
                ->select('source_id'))
            ->latest('id')
            ->first();
    }

    /** What a source-keyed redemption actually cost, read from the ledger. */
    private function redeemedValueForSource(string $sourceType, int $sourceId): ?float
    {
        $row = LoyaltyTransaction::withoutGlobalScopes()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('type', LoyaltyTransactionType::Redeemed->value)
            ->first();

        return $row === null ? null : abs((float) $row->value);
    }

    private function saccoIdForBooking(Booking $booking): ?int
    {
        $booking->loadMissing('queue.vehicle');

        return $booking->queue?->vehicle?->sacco_id !== null
            ? (int) $booking->queue->vehicle->sacco_id
            : null;
    }
}
