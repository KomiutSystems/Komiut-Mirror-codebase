<?php

declare(strict_types=1);

namespace App\Observers\Super\Fraud;

use App\Models\User;
use App\Services\Platform\PlatformEvent;
use App\Services\Platform\PlatformNotifier;
use Illuminate\Support\Facades\Context;

/**
 * One national ID belongs to one person, so the same id_number attached to more
 * than one account is either a data-entry collision or a driver being enrolled
 * twice — worth surfacing either way.
 *
 * The ID number is PII: it is hashed (sha1) for the dedupe key and the payload,
 * and the number itself never leaves. The check runs only when id_number was
 * actually written, so the every-request last_active_at touch does not trip it.
 */
final class DuplicateIdNumberObserver
{
    public function __construct(private readonly PlatformNotifier $notifier) {}

    public function saved(User $user): void
    {
        $idNumber = trim((string) ($user->id_number ?? ''));
        if ($idNumber === '') {
            return;
        }

        if (! $user->wasRecentlyCreated && ! $user->wasChanged('id_number')) {
            return;
        }

        $matches = User::query()
            ->where('id_number', $idNumber)
            ->get(['id', 'sacco_id']);

        if ($matches->count() <= 1) {
            return;
        }

        $hash = sha1($idNumber);
        $driverIds = $matches->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $saccoIds = $matches->pluck('sacco_id')->filter()->unique()
            ->map(fn ($id): int => (int) $id)->values()->all();

        $this->notifier->dispatch(new PlatformEvent(
            event: 'driver.id_number.duplicate',
            severity: 'high',
            class: 'alert',
            title: 'ID number shared by '.count($driverIds).' driver accounts',
            summary: 'One ID number is attached to '.count($driverIds).' accounts across '
                .count($saccoIds).' saccos.',
            brand: Context::has('brand') ? (string) Context::get('brand') : null,
            data: [
                'idNumberHash' => $hash,
                'driverIds' => $driverIds,
                'saccoIds' => $saccoIds,
            ],
            dedupeKey: 'driver.id_number.duplicate:'.$hash,
            windowMinutes: 24 * 60,
        ));
    }
}
