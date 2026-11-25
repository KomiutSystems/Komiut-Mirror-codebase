<?php

declare(strict_types=1);

namespace App\Observers\Money;

use App\Jobs\RegisterVehicleTill;
use App\Models\Vehicle;
use App\Services\Mpesa\TillRegistrar;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A bus saved with a till -- new, or with its till, head office or bank
 * details changed -- is registered with Safaricom without anyone asking. See
 * TillRegistrar.
 *
 * What can be known now is written now: "choose the head office" or "add the
 * store number" appears on the bus the moment it is saved, and only a real
 * registration waits for the queue.
 *
 * Never allowed to break the save it rides on. Vehicles are saved by driver
 * sign-in, onboarding, bank till requests, imports and the dashboard. Its
 * database work runs in a savepoint, so a failed query here cannot abort the
 * caller's transaction; anything that fails is reported and the bus keeps
 * whatever it said before.
 */
final class VehicleTillObserver
{
    private const WATCHED = ['merchant_short_code', 'mpesa_payment_setting_id', 'coop_till', 'ncba_till', 'sacco_id'];

    public function saved(Vehicle $vehicle): void
    {
        $relevant = $vehicle->wasRecentlyCreated
            ? filled($vehicle->merchant_short_code)
            : $vehicle->wasChanged(self::WATCHED);

        if (! $relevant) {
            return;
        }

        try {
            DB::transaction(fn () => $this->evaluate($vehicle));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function evaluate(Vehicle $vehicle): void
    {
        $registrar = app(TillRegistrar::class);

        if (! $registrar->isOurs($vehicle)) {
            $registrar->markNotOurs($vehicle);

            return;
        }

        // A bus that had a till and now has another, with its head office not
        // touched in the same save: the link it carries may have been filled
        // in for the OLD till, and the new one may sit under a different head
        // office. Safaricom keeps the first registration, so a person confirms
        // -- unless the SACCO has only one head office to choose from.
        $previous = (string) ($vehicle->getOriginal('merchant_short_code') ?? '');
        $tillChanged = $vehicle->wasChanged('merchant_short_code')
            && trim($previous) !== ''
            && $registrar->hasSeveralConnections($vehicle);
        // A bus moved to another SACCO still carries the old SACCO's head
        // office: registering an unregistered till under it would hand the
        // till to the wrong app for good.
        $movedWithItsOldLink = $vehicle->wasChanged('sacco_id')
            && $vehicle->mpesa_payment_setting_id !== null;
        if (! $vehicle->wasRecentlyCreated
            && ($tillChanged || $movedWithItsOldLink)
            && ! $vehicle->wasChanged('mpesa_payment_setting_id')
            && ! $registrar->isRegisteredHere($vehicle)) {
            $registrar->note($vehicle, TillRegistrar::CONFIRM_HEAD_OFFICE);

            return;
        }

        $status = $registrar->precheck($vehicle);
        if ($status !== TillRegistrar::PENDING) {
            // Settled without asking Safaricom: written as the registrar would.
            $registrar->register($vehicle);

            return;
        }

        $registrar->note($vehicle, TillRegistrar::PENDING);

        // Queued once the caller's transaction commits -- and a queue that
        // cannot be reached then must not throw out of someone else's commit.
        // The bus then says so, instead of promising a registration nobody
        // queued.
        $id = (int) $vehicle->id;
        DB::afterCommit(function () use ($id): void {
            try {
                RegisterVehicleTill::dispatch($id);
            } catch (Throwable $e) {
                report($e);
                $bus = Vehicle::withoutGlobalScopes()->find($id);
                if ($bus !== null) {
                    app(TillRegistrar::class)->giveUp($bus);
                }
            }
        });
    }
}
