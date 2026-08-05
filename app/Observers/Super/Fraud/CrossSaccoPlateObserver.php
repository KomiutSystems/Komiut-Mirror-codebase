<?php

declare(strict_types=1);

namespace App\Observers\Super\Fraud;

use App\Models\Vehicle;
use App\Services\Driver\VehicleAssignment;
use App\Services\Platform\PlatformEvent;
use App\Services\Platform\PlatformNotifier;

/**
 * A plate identifies one vehicle, which belongs to one sacco. The same plate — in
 * canonical form, so "KDQ 446R" and "KDQ446R" are one vehicle — sitting under two
 * saccos means a matatu claimed twice, a real dispute over fares and ownership.
 *
 * The stored `plate` column is uniquely indexed on its raw value, so this only
 * fires when spacing/case variants slipped two rows past that index; matching is
 * therefore done on the normalised form, the same normalisation logins use.
 *
 * The plate is public (painted on the vehicle), so it is not PII and is kept.
 */
final class CrossSaccoPlateObserver
{
    public function __construct(private readonly PlatformNotifier $notifier) {}

    public function saved(Vehicle $vehicle): void
    {
        $normalised = VehicleAssignment::normalisePlate((string) ($vehicle->plate ?? ''));
        if ($normalised === '') {
            return;
        }

        if (! $vehicle->wasRecentlyCreated && ! $vehicle->wasChanged('plate') && ! $vehicle->wasChanged('sacco_id')) {
            return;
        }

        $matches = Vehicle::withoutGlobalScopes()
            ->whereRaw("UPPER(REPLACE(plate, ' ', '')) = ?", [$normalised])
            ->get(['id', 'sacco_id', 'brand']);

        $saccoIds = $matches->pluck('sacco_id')->filter()->unique()
            ->map(fn ($id): int => (int) $id)->values()->all();

        if (count($saccoIds) <= 1) {
            return;
        }

        $vehicleIds = $matches->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $this->notifier->dispatch(new PlatformEvent(
            event: 'vehicle.plate.cross_sacco',
            severity: 'high',
            class: 'alert',
            title: 'Plate '.$normalised.' registered under '.count($saccoIds).' saccos',
            summary: 'Plate '.$normalised.' is registered to '.count($saccoIds).' saccos on '
                .count($vehicleIds).' vehicle records.',
            brand: $vehicle->brand,
            subject: ['type' => 'vehicle', 'id' => (int) $vehicle->id, 'label' => $normalised],
            data: [
                'plate' => $normalised,
                'saccoIds' => $saccoIds,
                'vehicleIds' => $vehicleIds,
            ],
            dedupeKey: 'vehicle.plate.cross_sacco:'.$normalised,
            windowMinutes: 24 * 60,
        ));
    }
}
