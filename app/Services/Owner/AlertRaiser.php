<?php

declare(strict_types=1);

namespace App\Services\Owner;

use App\Enums\NotificationType;
use App\Models\OwnerNotificationSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAlert;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Carbon;

/**
 * Raises and resolves a bus alert, once per episode.
 *
 * "Once per episode" is the whole point: a bus that stays offline for three
 * hours gets ONE push, not thirty-six. The open row IS the episode, so a second
 * detection while one is open does nothing.
 */
class AlertRaiser
{
    public function __construct(private readonly NotificationService $notifications) {}

    /** @param array<string, mixed> $details */
    public function raise(Vehicle $vehicle, string $type, array $details, array $pushParams, string $titleKey, string $bodyKey, ?int $minutesQuiet = null): int
    {
        $raised = 0;

        foreach ($this->ownersOf($vehicle) as $owner) {
            $settings = OwnerNotificationSetting::forOwner((int) $owner->id);

            if (! $this->wants($settings, $type)) {
                continue;
            }

            // Each owner picks their own 15/30/60. One co-owner choosing 30
            // must not raise an alert for another who chose 60 — and an owner
            // whose threshold is not met yet has any open episode closed.
            if ($minutesQuiet !== null && $minutesQuiet < (int) $settings['offline_after_minutes']) {
                $this->resolveFor((int) $owner->id, (int) $vehicle->id, $type);

                continue;
            }

            $open = VehicleAlert::where('vehicle_id', $vehicle->id)
                ->where('owner_id', $owner->id)
                ->where('type', $type)
                ->whereNull('resolved_at')
                ->exists();

            if ($open) {
                continue;
            }

            $alert = VehicleAlert::create([
                'owner_id' => $owner->id,
                'vehicle_id' => $vehicle->id,
                'type' => $type,
                'details' => $details,
                'raised_at' => Carbon::now(),
            ]);
            $raised++;

            if (! config('owner.push_enabled')) {
                continue;
            }

            $params = $pushParams + ['plate' => $vehicle->plate];

            $this->notifications->dispatch(
                $owner,
                NotificationType::VehicleAlert,
                (string) __($titleKey, $params),
                (string) __($bodyKey, $params),
                referenceId: $alert->publicId(),
                titleKey: $titleKey,
                messageKey: $bodyKey,
                params: $params,
            );
        }

        return $raised;
    }

    /** Close one owner's open episode on a bus. */
    public function resolveFor(int $ownerId, int $vehicleId, string $type): int
    {
        return VehicleAlert::where('owner_id', $ownerId)
            ->where('vehicle_id', $vehicleId)
            ->where('type', $type)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => Carbon::now()]);
    }

    /** The episode ended: the bus reported again, or took its first payment. */
    public function resolve(int $vehicleId, string $type): int
    {
        return VehicleAlert::where('vehicle_id', $vehicleId)
            ->where('type', $type)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => Carbon::now()]);
    }

    /** Everyone holding an open assignment on this bus who is an owner. */
    private function ownersOf(Vehicle $vehicle)
    {
        return OwnedVehicles::all()
            ->filter(fn (User $u): bool => in_array((int) $vehicle->id, OwnedVehicles::forUser($u), true));
    }

    /** @param array<string, mixed> $settings */
    private function wants(array $settings, string $type): bool
    {
        return match ($type) {
            VehicleAlert::OFFLINE => $settings['offline_alert_enabled'] === true,
            VehicleAlert::NO_COLLECTIONS => $settings['noon_alert_enabled'] === true,
            default => true,
        };
    }
}
