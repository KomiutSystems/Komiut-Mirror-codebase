<?php

declare(strict_types=1);

namespace App\Services\Super\Money;

use App\Services\Platform\PlatformEvent;
use App\Services\Platform\PlatformNotifier;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Event 3 — payment.reconciliation.failed.
 *
 * Every M-Pesa callback that can't be matched to a booking (or a vehicle, on the
 * C2B path) is a payment we received but can't attribute. Rather than one alert
 * per orphan, failures are AGGREGATED per brand in a rolling cache window and
 * surfaced as a single throttled notification whose payload carries the running
 * count, unattributed total, and a few sample references for the on-call to chase.
 *
 * The cache aggregate and PlatformNotifier's own dedupe window are kept aligned
 * (both 15m): the notifier collapses repeats onto one open row while this keeps
 * the running totals the row's `data` reports.
 */
final class PaymentReconciliationAlerter
{
    private const WINDOW_MINUTES = 15;

    private const SAMPLE_LIMIT = 5;

    /**
     * Record one unmatched callback and (re)emit the aggregated alert.
     *
     * @param  string  $ref  a booking id / trans id — an operational reference, not PII
     */
    public function record(?string $brand, string $ref, float $amount): void
    {
        // Guarded: this runs inside Daraja webhooks and the settlement path. A
        // console-alert failure must never turn an ack into a 500 / retry storm.
        try {
            $this->emit($brand, $ref, $amount);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function emit(?string $brand, string $ref, float $amount): void
    {
        $key = 'super:money:recon_fail:'.($brand ?? '_');

        /** @var array{count:int,amount:float,refs:array<int,string>} $state */
        $state = Cache::get($key, ['count' => 0, 'amount' => 0.0, 'refs' => []]);
        $state['count']++;
        $state['amount'] += $amount;
        if ($ref !== '' && count($state['refs']) < self::SAMPLE_LIMIT && ! in_array($ref, $state['refs'], true)) {
            $state['refs'][] = $ref;
        }
        Cache::put($key, $state, now()->addMinutes(self::WINDOW_MINUTES));

        app(PlatformNotifier::class)->dispatch(new PlatformEvent(
            event: 'payment.reconciliation.failed',
            severity: 'critical',
            class: 'alert',
            title: 'M-Pesa payments not matching bookings',
            summary: $state['count'].' unmatched M-Pesa callback(s) in '.self::WINDOW_MINUTES.'m; KES '
                .number_format($state['amount'], 0).' unattributed.',
            brand: $brand,
            subject: ['type' => 'brand', 'id' => $brand ?? 'unknown'],
            data: [
                'failureCount' => $state['count'],
                'window' => self::WINDOW_MINUTES.'m',
                'sampleRefs' => array_values($state['refs']),
                'totalAmount' => round($state['amount'], 2),
            ],
            dedupeKey: 'payment.reconciliation.failed:'.($brand ?? '_'),
            windowMinutes: self::WINDOW_MINUTES,
        ));
    }
}
