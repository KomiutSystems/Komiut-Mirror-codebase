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
     * Per-SACCO reward cards for the passenger's loyalty screen — redeemable
     * first, then closest to a reward.
     *
     * @return array<int, array<string, mixed>>
     */
    public function summary(int $userId): array
    {
        $accounts = LoyaltyAccount::withoutGlobalScopes()->where('user_id', $userId)->get();
        if ($accounts->isEmpty()) {
            return [];
        }

        $saccoIds = $accounts->pluck('sacco_id');
        $programs = LoyaltyProgram::withoutGlobalScopes()->whereIn('sacco_id', $saccoIds)->get()->keyBy('sacco_id');
        $saccoNames = Sacco::withoutGlobalScopes()->whereIn('id', $saccoIds)->pluck('name', 'id');

        return $accounts
            ->map(function (LoyaltyAccount $account) use ($programs, $saccoNames) {
                $program = $programs->get($account->sacco_id);
                $threshold = (float) ($program->redemption_threshold ?? 0);
                $active = $program !== null && $program->is_active;
                $eligible = $active && $threshold > 0 && $account->balance >= $threshold;

                return [
                    'sacco_id' => $account->sacco_id,
                    'sacco' => $saccoNames[$account->sacco_id] ?? null,
                    'balance' => round($account->balance, 2),
                    'redemption_threshold' => $threshold,
                    'points_to_reward' => round(max(0, $threshold - $account->balance), 2),
                    'eligible_to_redeem' => $eligible,
                    'is_active' => $active,
                ];
            })
            ->sortBy([['eligible_to_redeem', 'desc'], ['points_to_reward', 'asc']])
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
