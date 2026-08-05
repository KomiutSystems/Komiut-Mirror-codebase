<?php

declare(strict_types=1);

namespace App\Observers\Super\Money;

use App\Models\Vehicle;
use App\Services\Platform\AuditLogger;
use App\Services\Platform\PlatformEvent;
use App\Services\Platform\PlatformNotifier;
use Throwable;

/**
 * Events 1 & 2 — the highest-value money-integrity signals.
 *
 *   vehicle.payment_details.changed  a vehicle's till_number / merchant_short_code
 *                                    was changed — a money-redirect. AUDIT-FIRST,
 *                                    NEVER throttled (windowMinutes 0).
 *   vehicle.till.duplicate           the new till/merchant is already in use by
 *                                    another vehicle — money could land on the
 *                                    wrong plate.
 *
 * Fires only on the fields that actually changed, so an unrelated vehicle edit is
 * silent and the duplicate probe runs only when the identifier itself moved.
 */
final class VehiclePaymentObserver
{
    /** @var array<int,string> */
    private const PAYMENT_FIELDS = ['till_number', 'merchant_short_code'];

    public function updated(Vehicle $vehicle): void
    {
        // Guarded: the alert must never break the vehicle save that triggered it.
        try {
            $this->emit($vehicle);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function emit(Vehicle $vehicle): void
    {
        foreach (self::PAYMENT_FIELDS as $field) {
            if (! $vehicle->wasChanged($field)) {
                continue;
            }

            $from = $vehicle->getOriginal($field);
            $to = $vehicle->getAttribute($field);
            $brand = $vehicle->brand !== null ? (string) $vehicle->brand : null;

            $data = [
                'vehicleId' => (int) $vehicle->id,
                'plate' => (string) $vehicle->plate,
                'saccoId' => $vehicle->sacco_id !== null ? (int) $vehicle->sacco_id : null,
                'field' => $field,
                'from' => $from !== null ? (string) $from : null,
                'to' => $to !== null ? (string) $to : null,
            ];

            // AUDIT-FIRST: the immutable record precedes the (dismissable) alert.
            $audit = AuditLogger::record(
                'vehicle.payment_details.changed',
                $data,
                null,
                ['type' => 'vehicle', 'id' => (string) $vehicle->id],
                $brand,
            );

            app(PlatformNotifier::class)->dispatch(new PlatformEvent(
                event: 'vehicle.payment_details.changed',
                severity: 'critical',
                class: 'alert',
                title: 'Vehicle payment details changed',
                summary: 'Vehicle '.$vehicle->plate.' '.$field.' changed.',
                brand: $brand,
                actor: ['type' => $audit->actor_type, 'id' => $audit->actor_id, 'label' => $audit->actor_label],
                subject: ['type' => 'vehicle', 'id' => (string) $vehicle->id],
                data: $data,
                windowMinutes: 0,
                auditId: $audit->id,
            ));

            $this->checkDuplicate($vehicle, $field, $to !== null ? (string) $to : null, $brand);
        }
    }

    /**
     * Event 2: the same till/merchant used by more than one vehicle. Scanned
     * cross-brand (withoutGlobalScopes) — a redirect can point at any brand.
     */
    private function checkDuplicate(Vehicle $vehicle, string $field, ?string $value, ?string $brand): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $matches = Vehicle::withoutGlobalScopes()
            ->where($field, $value)
            ->get(['id', 'sacco_id']);

        if ($matches->count() < 2) {
            return;
        }

        app(PlatformNotifier::class)->dispatch(new PlatformEvent(
            event: 'vehicle.till.duplicate',
            severity: 'critical',
            class: 'alert',
            title: 'Till shared by multiple vehicles',
            summary: $field.' '.$value.' is used by '.$matches->count().' vehicles.',
            brand: $brand,
            subject: ['type' => 'vehicle', 'id' => (string) $vehicle->id],
            data: [
                'tillNumber' => $value,
                'field' => $field,
                'vehicleIds' => $matches->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
                'saccoIds' => $matches->pluck('sacco_id')->filter()
                    ->map(static fn ($id): int => (int) $id)->unique()->values()->all(),
            ],
            windowMinutes: 0,
        ));
    }
}
