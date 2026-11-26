<?php

declare(strict_types=1);

namespace App\Observers\Team;

use App\Models\Vehicle;
use App\Services\Team\AutoCards\AutoCards;

/**
 * Bus changes the ops board's till and head-office cards follow.
 *
 * Most till outcomes arrive as TillRegistrationRecorded (the registrar writes
 * them past the model's events); this catches the ones written by a normal
 * save -- a bank-issued till applied (TillRequestsController) -- and a bus
 * moving SACCO or being deleted. Vehicles are saved on every driver sign-in,
 * so nothing here queries: it notes ids, and only when a watched column moved.
 */
final class VehicleCardsObserver
{
    public function saved(Vehicle $vehicle): void
    {
        if ($vehicle->wasRecentlyCreated || ! $vehicle->wasChanged(['till_registration_status', 'sacco_id'])) {
            return;
        }

        $cards = app(AutoCards::class);

        if ($vehicle->wasChanged('till_registration_status')) {
            $cards->till((int) $vehicle->id);
        }

        // A bus that moved takes its wait on a head office with it.
        foreach (array_unique([$vehicle->sacco_id, $vehicle->wasChanged('sacco_id') ? $vehicle->getOriginal('sacco_id') : null]) as $saccoId) {
            if ($saccoId !== null) {
                $cards->headOffice((int) $saccoId);
            }
        }
    }

    public function deleted(Vehicle $vehicle): void
    {
        $cards = app(AutoCards::class);
        $cards->till((int) $vehicle->id);
        if ($vehicle->sacco_id !== null) {
            $cards->headOffice((int) $vehicle->sacco_id);
        }
    }
}
