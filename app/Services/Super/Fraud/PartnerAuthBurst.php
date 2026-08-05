<?php

declare(strict_types=1);

namespace App\Services\Super\Fraud;

use App\Services\Platform\PlatformEvent;
use App\Services\Platform\PlatformNotifier;
use App\Services\Platform\Thresholds;
use Illuminate\Support\Facades\Cache;

/**
 * The partner bank portal is guarded by ONE shared key per bank — there are no
 * individual accounts, so a run of wrong keys is the only visible sign of someone
 * trying to guess their way into a bank's driver list.
 *
 * A rejected key does not resolve to a partner (that is the whole point), so the
 * burst is bucketed by source IP and `partnerAttempted` stays null. The presented
 * key is a near-miss secret and is never stored.
 */
final class PartnerAuthBurst
{
    public function __construct(private readonly PlatformNotifier $notifier) {}

    public function recordFailure(?string $ip): void
    {
        $source = ($ip !== null && $ip !== '') ? $ip : 'unknown';

        // The key is unresolved, so there is no brand — defaults apply.
        $threshold = Thresholds::get(null, 'partner_auth_burst');
        $count = (int) ($threshold['count'] ?? 5);
        $window = (int) ($threshold['window_minutes'] ?? 15);

        $key = 'fraud:partner_auth_burst:'.$source;
        $bucket = Cache::get($key, ['count' => 0, 'ips' => []]);

        $bucket['count']++;
        $bucket['ips'][$source] = true;

        Cache::put($key, $bucket, now()->addMinutes($window));

        if ($bucket['count'] < $count) {
            return;
        }

        $sourceIps = array_keys($bucket['ips']);

        $this->notifier->dispatch(new PlatformEvent(
            event: 'partner.auth.failed_burst',
            severity: 'critical',
            class: 'alert',
            title: 'Failed partner-key burst from '.$source,
            summary: $bucket['count'].' rejected partner keys from '.$source.' within '.$window.' min.',
            brand: null,
            data: [
                'partnerAttempted' => null,
                'attemptCount' => $bucket['count'],
                'sourceIps' => $sourceIps,
            ],
            dedupeKey: 'partner.auth.failed_burst:'.$source,
            windowMinutes: $window,
        ));
    }
}
