<?php

declare(strict_types=1);

namespace App\Services\Super\Money;

use App\Services\Platform\PlatformEvent;
use App\Services\Platform\PlatformNotifier;
use App\Services\Platform\Thresholds;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Event 4 — loyalty.earn.failure_rate_high.
 *
 * Loyalty earning was moved to a savepoint+catch so a failing earn can never
 * disturb the payment that triggered it (see App\Listeners\EarnLoyaltyPoints) —
 * which means every earn failure is now SILENT. This is the compensating control:
 * a rolling per-brand attempt/failure counter that raises a throttled alert once
 * the failure rate crosses the per-brand threshold over a meaningful sample.
 *
 * Counters live in a cache entry per brand with a TTL equal to the threshold
 * window, giving a coarse rolling window (reset on TTL expiry) — sufficient for a
 * rate signal without a dedicated table.
 */
final class LoyaltyEarnFailureTracker
{
    public function recordAttempt(?string $brand): void
    {
        // Guarded: this runs inside the settlement path (see EarnLoyaltyPoints) —
        // tracking must never disturb the payment.
        try {
            // Count the attempt only — a plain attempt can never trip the alert.
            $this->bump($brand, attempts: 1, failures: 0);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function recordFailure(?string $brand): void
    {
        try {
            $this->evaluateFailure($brand);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function evaluateFailure(?string $brand): void
    {
        $config = $this->config($brand);
        $state = $this->bump($brand, attempts: 0, failures: 1);

        $attempts = $state['attempts'];
        $failures = $state['failures'];

        if ($attempts < $config['min_attempts']) {
            return;
        }

        $rate = $attempts > 0 ? $failures / $attempts : 0.0;
        if ($rate < $config['rate']) {
            return;
        }

        app(PlatformNotifier::class)->dispatch(new PlatformEvent(
            event: 'loyalty.earn.failure_rate_high',
            severity: 'critical',
            class: 'alert',
            title: 'Loyalty earning is failing silently',
            summary: round($rate * 100, 1).'% of loyalty earns failed ('.$failures.'/'.$attempts
                .') in '.$config['window_minutes'].'m.',
            brand: $brand,
            subject: ['type' => 'brand', 'id' => $brand ?? 'unknown'],
            data: [
                'failureCount' => $failures,
                'attemptCount' => $attempts,
                'rate' => round($rate, 4),
            ],
            dedupeKey: 'loyalty.earn.failure_rate_high:'.($brand ?? '_'),
            windowMinutes: $config['window_minutes'],
        ));
    }

    /**
     * @return array{attempts:int,failures:int}
     */
    private function bump(?string $brand, int $attempts, int $failures): array
    {
        $key = 'super:money:loyalty_earn:'.($brand ?? '_');
        $ttl = $this->config($brand)['window_minutes'];

        /** @var array{attempts:int,failures:int} $state */
        $state = Cache::get($key, ['attempts' => 0, 'failures' => 0]);
        $state['attempts'] += $attempts;
        $state['failures'] += $failures;
        Cache::put($key, $state, now()->addMinutes($ttl));

        return $state;
    }

    /**
     * @return array{rate:float,window_minutes:int,min_attempts:int}
     */
    private function config(?string $brand): array
    {
        $config = Thresholds::get($brand, 'loyalty_earn_failure');
        $config = is_array($config) ? $config : [];

        return [
            'rate' => (float) ($config['rate'] ?? 0.02),
            'window_minutes' => (int) ($config['window_minutes'] ?? 30),
            'min_attempts' => (int) ($config['min_attempts'] ?? 20),
        ];
    }
}
