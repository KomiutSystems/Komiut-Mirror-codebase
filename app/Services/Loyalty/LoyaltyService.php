<?php

declare(strict_types=1);

namespace App\Services\Loyalty;

use App\Enums\LoyaltyTransactionType;
use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyTransaction;
use App\Models\Sacco;
use App\Models\Scopes\SaccoScope;
use App\Models\User;
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
        if ((bool) $booking->paid) {
            return ['ok' => false, 'status' => 422, 'error' => 'This booking is already paid.'];
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

        return DB::transaction(function () use ($user, $booking, $saccoId, $cost) {
            if (! $this->debit((int) $user->id, $saccoId, $cost, (int) $booking->id)) {
                return ['ok' => false, 'status' => 422, 'error' => 'You do not have enough points for a free ride.'];
            }

            $booking->paid = true;
            $booking->payment_method = PaymentMethod::LoyaltyPoints;
            $booking->save();

            return ['ok' => true, 'booking' => $booking, 'points_spent' => $cost];
        });
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
     * SCOPING. BrandScope still applies, so a Komiut passenger is never offered a
     * 2Safiri SACCO. SaccoScope is dropped deliberately: it fails closed on a
     * NULL sacco_id, and a passenger belongs to no SACCO by definition — the same
     * reason Sacco itself opts into cross-tenant browsing for the public
     * directory. It is dropped HERE, at the one call site that wants it, rather
     * than on the model, so the SACCO- and admin-facing loyalty endpoints keep
     * the tenant wall they rely on.
     *
     * @return array<int, array<string, mixed>>
     */
    public function summary(int $userId): array
    {
        $active = LoyaltyProgram::withoutGlobalScope(SaccoScope::class)
            ->where('is_active', true)
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

    // ---- internals ----

    private function credit(int $userId, int $saccoId, float $points, LoyaltyTransactionType $type, ?int $bookingId): ?LoyaltyTransaction
    {
        return DB::transaction(function () use ($userId, $saccoId, $points, $type, $bookingId) {
            if ($bookingId !== null && $this->hasType($bookingId, $type)) {
                return LoyaltyTransaction::withoutGlobalScopes()
                    ->where('booking_id', $bookingId)->where('type', $type->value)->first();
            }

            $tx = LoyaltyTransaction::create([
                'user_id' => $userId, 'sacco_id' => $saccoId, 'value' => $points,
                'type' => $type, 'booking_id' => $bookingId,
            ]);

            $account = LoyaltyAccount::withoutGlobalScopes()
                ->firstOrCreate(['user_id' => $userId, 'sacco_id' => $saccoId], ['balance' => 0]);
            LoyaltyAccount::withoutGlobalScopes()->whereKey($account->id)->increment('balance', $points);

            return $tx;
        });
    }

    /** Atomic guarded decrement — the double-spend guard is the DB predicate. */
    private function debit(int $userId, int $saccoId, float $cost, ?int $bookingId): bool
    {
        if ($bookingId !== null && $this->hasType($bookingId, LoyaltyTransactionType::Redeemed)) {
            return true; // already redeemed for this booking
        }

        $affected = LoyaltyAccount::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->where('sacco_id', $saccoId)
            ->where('balance', '>=', $cost)
            ->decrement('balance', $cost);

        if ($affected === 0) {
            return false;
        }

        LoyaltyTransaction::create([
            'user_id' => $userId, 'sacco_id' => $saccoId, 'value' => -$cost,
            'type' => LoyaltyTransactionType::Redeemed, 'booking_id' => $bookingId,
        ]);

        return true;
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
