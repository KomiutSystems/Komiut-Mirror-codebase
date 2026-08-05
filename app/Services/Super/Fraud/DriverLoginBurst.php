<?php

declare(strict_types=1);

namespace App\Services\Super\Fraud;

use App\Services\Driver\VehicleAssignment;
use App\Services\Platform\PlatformEvent;
use App\Services\Platform\PlatformNotifier;
use App\Services\Platform\Thresholds;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;

/**
 * Driver check-in is phone + number plate, no password and no OTP. The plate is
 * painted on the vehicle and public, so the phone is the only secret — which makes
 * a run of failed logins against one plate a phone-guessing attempt against that
 * vehicle's driver.
 *
 * Two signals live here because they share the same per-plate state:
 *   driver.login.failed_burst      — N failed logins on a plate inside the window.
 *   driver.login.suspicious_success — a login that succeeds on a plate that just
 *                                     saw a failed burst (the guess landed).
 *
 * PII rule: phone numbers are the secret, so we never store them — distinct
 * attempts are counted from sha1 hashes and only the count leaves. Plate, vehicle,
 * sacco and source IPs are not secret (the plate is public) and are kept.
 */
final class DriverLoginBurst
{
    /** How long a failed burst keeps "arming" a following success as suspicious. */
    private const SUCCESS_WINDOW_MINUTES = 60;

    public function __construct(private readonly PlatformNotifier $notifier) {}

    /**
     * Record one failed driver login against a plate and raise the burst alert
     * once the per-plate failure count reaches the brand threshold.
     */
    public function recordFailure(
        string $plate,
        ?string $phone,
        ?string $ip,
        ?int $vehicleId = null,
        ?int $saccoId = null,
    ): void {
        $normalised = VehicleAssignment::normalisePlate($plate);
        if ($normalised === '') {
            return;
        }

        $brand = $this->brand();
        $threshold = Thresholds::get($brand, 'driver_login_burst');
        $count = (int) ($threshold['count'] ?? 5);
        $window = (int) ($threshold['window_minutes'] ?? 15);

        $bucket = Cache::get($this->failureKey($normalised), [
            'count' => 0, 'phones' => [], 'ips' => [], 'vehicleId' => null, 'saccoId' => null,
        ]);

        $bucket['count']++;
        if ($phone !== null && $phone !== '') {
            $hash = sha1($phone); // the phone is the secret — hash it, never store it
            $bucket['phones'][$hash] = true;
        }
        if ($ip !== null && $ip !== '') {
            $bucket['ips'][$ip] = true;
        }
        $bucket['vehicleId'] ??= $vehicleId;
        $bucket['saccoId'] ??= $saccoId;

        Cache::put($this->failureKey($normalised), $bucket, now()->addMinutes($window));

        if ($bucket['count'] < $count) {
            return;
        }

        // Arm suspicious-success detection: a login that succeeds on this plate
        // within the hour was preceded by this many failures.
        Cache::put($this->armedKey($normalised), $bucket['count'], now()->addMinutes(self::SUCCESS_WINDOW_MINUTES));

        $sourceIps = array_keys($bucket['ips']);
        $this->notifier->dispatch(new PlatformEvent(
            event: 'driver.login.failed_burst',
            severity: 'critical',
            class: 'alert',
            title: 'Failed driver-login burst on plate '.$normalised,
            summary: $bucket['count'].' failed logins on plate '.$normalised.' from '
                .count($bucket['phones']).' phones, '.count($sourceIps).' IPs.',
            brand: $brand,
            subject: ['type' => 'vehicle', 'id' => $bucket['vehicleId'], 'label' => $normalised],
            data: [
                'plate' => $normalised,
                'vehicleId' => $bucket['vehicleId'],
                'saccoId' => $bucket['saccoId'],
                'attemptCount' => $bucket['count'],
                'distinctPhones' => count($bucket['phones']),
                'sourceIps' => $sourceIps,
            ],
            dedupeKey: 'driver.login.failed_burst:'.$normalised,
            windowMinutes: $window,
        ));
    }

    /**
     * A driver login just succeeded on this plate. If the plate is still armed
     * from a recent failed burst, raise driver.login.suspicious_success (never
     * throttled), then clear the per-plate state so the next shift starts clean.
     */
    public function noteSuccess(string $plate, int $driverId, ?string $ip): void
    {
        $normalised = VehicleAssignment::normalisePlate($plate);
        if ($normalised === '') {
            return;
        }

        $precededBy = Cache::get($this->armedKey($normalised));

        Cache::forget($this->failureKey($normalised));
        Cache::forget($this->armedKey($normalised));

        if ($precededBy === null) {
            return;
        }

        $brand = $this->brand();
        $this->notifier->dispatch(new PlatformEvent(
            event: 'driver.login.suspicious_success',
            severity: 'critical',
            class: 'alert',
            title: 'Driver login succeeded after a failed burst on '.$normalised,
            summary: 'Login on plate '.$normalised.' succeeded after '.(int) $precededBy.' recent failed attempts.',
            brand: $brand,
            subject: ['type' => 'driver', 'id' => $driverId, 'label' => $normalised],
            data: [
                'plate' => $normalised,
                'driverId' => $driverId,
                'precededByFailures' => (int) $precededBy,
                'ip' => $ip,
            ],
            dedupeKey: 'driver.login.suspicious_success:'.$normalised,
            windowMinutes: 0, // audit-critical: never throttle
        ));
    }

    private function failureKey(string $normalisedPlate): string
    {
        return 'fraud:driver_login_burst:'.$normalisedPlate;
    }

    private function armedKey(string $normalisedPlate): string
    {
        return 'fraud:driver_login_armed:'.$normalisedPlate;
    }

    private function brand(): ?string
    {
        return Context::has('brand') ? (string) Context::get('brand') : null;
    }
}
