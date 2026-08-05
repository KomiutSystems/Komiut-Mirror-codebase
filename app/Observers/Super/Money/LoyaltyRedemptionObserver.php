<?php

declare(strict_types=1);

namespace App\Observers\Super\Money;

use App\Enums\LoyaltyTransactionType;
use App\Models\LoyaltyTransaction;
use App\Models\Sacco;
use App\Services\Platform\PlatformEvent;
use App\Services\Platform\PlatformNotifier;
use App\Services\Platform\Thresholds;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Event 6 — loyalty.redemption.spike.
 *
 * A burst of point redemptions for one SACCO can mean a compromised program or an
 * abuse ring cashing points for free rides. On each new redemption we compare the
 * trailing window's redemption count to the immediately preceding window of the
 * same length (a simple trailing baseline) and alert on a clear jump.
 *
 * SIMPLIFICATION: the baseline is the single prior window rather than a smoothed
 * average, and a small floor suppresses noise on low-volume SACCOs. Throttled to
 * one alert per SACCO per window.
 */
final class LoyaltyRedemptionObserver
{
    private const FLOOR = 5;

    public function created(LoyaltyTransaction $transaction): void
    {
        // Guarded: redemption runs inside a DB transaction (LoyaltyService::debit);
        // a spike-alert failure must never roll back the passenger's redemption.
        try {
            $this->emit($transaction);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function emit(LoyaltyTransaction $transaction): void
    {
        if ($transaction->type !== LoyaltyTransactionType::Redeemed || $transaction->sacco_id === null) {
            return;
        }

        $saccoId = (int) $transaction->sacco_id;
        $brand = Sacco::withoutGlobalScopes()->whereKey($saccoId)->value('brand');
        $brand = $brand !== null ? (string) $brand : null;

        $hours = (int) (Thresholds::get($brand, 'redemption_spike_window_hours') ?? 6);
        $now = now();
        $currentStart = $now->copy()->subHours($hours);
        $baselineStart = $now->copy()->subHours($hours * 2);

        $current = $this->redemptions($saccoId)
            ->where('created_at', '>=', $currentStart)
            ->count();

        $baseline = $this->redemptions($saccoId)
            ->where('created_at', '>=', $baselineStart)
            ->where('created_at', '<', $currentStart)
            ->count();

        // A spike: enough absolute volume to matter AND more than double the
        // preceding window (or any real volume against a quiet baseline).
        $isSpike = $current >= self::FLOOR && $current > max($baseline * 2, self::FLOOR - 1);
        if (! $isSpike) {
            return;
        }

        $pointsBurned = abs((float) $this->redemptions($saccoId)
            ->where('created_at', '>=', $currentStart)
            ->sum('value'));

        app(PlatformNotifier::class)->dispatch(new PlatformEvent(
            event: 'loyalty.redemption.spike',
            severity: 'high',
            class: 'alert',
            title: 'Loyalty redemption spike',
            summary: $current.' redemptions in '.$hours.'h for one SACCO (baseline '.$baseline.').',
            brand: $brand,
            subject: ['type' => 'sacco', 'id' => (string) $saccoId],
            data: [
                'saccoId' => $saccoId,
                'redemptionCount' => $current,
                'baseline' => $baseline,
                'pointsBurned' => round($pointsBurned, 2),
            ],
            dedupeKey: 'loyalty.redemption.spike:sacco:'.$saccoId,
            windowMinutes: $hours * 60,
        ));
    }

    private function redemptions(int $saccoId): Builder
    {
        return LoyaltyTransaction::withoutGlobalScopes()
            ->where('sacco_id', $saccoId)
            ->where('type', LoyaltyTransactionType::Redeemed->value);
    }
}
