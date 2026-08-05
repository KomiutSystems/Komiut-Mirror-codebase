<?php

declare(strict_types=1);

namespace App\Services\Super\Fraud;

use App\Services\Platform\PlatformEvent;
use App\Services\Platform\PlatformNotifier;
use App\Services\Platform\Thresholds;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;

/**
 * Street onboarding is unauthenticated — a marketing agent signs a driver up on
 * their own phone — so the only handle on the actor is the source IP. One IP
 * onboarding more than the brand's hourly threshold of drivers is either a runaway
 * script or an agent manufacturing sign-ups, both worth a look.
 */
final class OnboardingVelocity
{
    public function __construct(private readonly PlatformNotifier $notifier) {}

    public function record(?string $ip, ?int $saccoId): void
    {
        $actorRef = ($ip !== null && $ip !== '') ? $ip : 'unknown';

        $brand = $this->brand();
        $threshold = Thresholds::get($brand, 'onboarding_velocity');
        $count = (int) ($threshold['count'] ?? 15);
        $window = (int) ($threshold['window_minutes'] ?? 60);

        $key = 'fraud:onboarding_velocity:'.$actorRef;
        $bucket = Cache::get($key, ['count' => 0, 'saccos' => []]);

        $bucket['count']++;
        if ($saccoId !== null) {
            $bucket['saccos'][(int) $saccoId] = true;
        }

        Cache::put($key, $bucket, now()->addMinutes($window));

        // Strictly ">" the threshold: N per hour is allowed, N+1 is the spike.
        if ($bucket['count'] <= $count) {
            return;
        }

        $saccoIds = array_map('intval', array_keys($bucket['saccos']));

        $this->notifier->dispatch(new PlatformEvent(
            event: 'onboarding.velocity.spike',
            severity: 'high',
            class: 'alert',
            title: 'Onboarding spike from one origin',
            summary: $bucket['count'].' drivers onboarded from '.$actorRef.' within '.$window.' min.',
            brand: $brand,
            actor: ['type' => 'agent', 'id' => $actorRef, 'label' => $actorRef],
            data: [
                'actorRef' => $actorRef,
                'count' => $bucket['count'],
                'window' => $window,
                'saccoIds' => $saccoIds,
            ],
            dedupeKey: 'onboarding.velocity.spike:'.$actorRef,
            windowMinutes: $window,
        ));
    }

    private function brand(): ?string
    {
        return Context::has('brand') ? (string) Context::get('brand') : null;
    }
}
