<?php

declare(strict_types=1);

namespace App\Services\Super\Fraud;

use App\Models\User;
use App\Models\Vehicle;
use App\Services\Platform\PlatformEvent;
use App\Services\Platform\PlatformNotifier;
use App\Services\Platform\Thresholds;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;

/**
 * A driver normally logs into one matatu a day. The same driver being attached to
 * several distinct vehicles inside 24h — via logins or onboarding, both of which
 * run through VehicleAssignment::assign — is the shape of a shared or laundered
 * driver account, so it is worth surfacing.
 *
 * Distinct vehicles are counted from a per-driver set held for the threshold
 * window; re-assigning the same vehicle (the idempotent daily login) never inflates
 * the count.
 */
final class RapidReassignDetector
{
    public function __construct(private readonly PlatformNotifier $notifier) {}

    public function record(User $driver, Vehicle $vehicle): void
    {
        $driverId = (int) $driver->id;
        $vehicleId = (int) $vehicle->id;

        $brand = $vehicle->brand ?? $this->brand();
        $threshold = Thresholds::get($brand, 'driver_rapid_reassign');
        $count = (int) ($threshold['count'] ?? 3);
        $windowHours = (int) ($threshold['window_hours'] ?? 24);

        $key = 'fraud:driver_rapid_reassign:'.$driverId;
        $bucket = Cache::get($key, ['vehicles' => [], 'saccos' => []]);

        $bucket['vehicles'][$vehicleId] = true;
        if ($vehicle->sacco_id !== null) {
            $bucket['saccos'][(int) $vehicle->sacco_id] = true;
        }

        Cache::put($key, $bucket, now()->addHours($windowHours));

        if (count($bucket['vehicles']) < $count) {
            return;
        }

        $vehicleIds = array_map('intval', array_keys($bucket['vehicles']));
        $saccoIds = array_map('intval', array_keys($bucket['saccos']));

        $this->notifier->dispatch(new PlatformEvent(
            event: 'driver.reassigned.rapid',
            severity: 'high',
            class: 'alert',
            title: 'Driver attached to '.count($vehicleIds).' vehicles in '.$windowHours.'h',
            summary: 'Driver #'.$driverId.' attached to '.count($vehicleIds).' vehicles across '
                .count($saccoIds).' saccos in '.$windowHours.'h.',
            brand: $brand,
            subject: ['type' => 'driver', 'id' => $driverId, 'label' => 'Driver #'.$driverId],
            data: [
                'driverId' => $driverId,
                'vehicleIds' => $vehicleIds,
                'saccoIds' => $saccoIds,
                'windowHours' => $windowHours,
            ],
            dedupeKey: 'driver.reassigned.rapid:'.$driverId,
            windowMinutes: $windowHours * 60,
        ));
    }

    private function brand(): ?string
    {
        return Context::has('brand') ? (string) Context::get('brand') : null;
    }
}
