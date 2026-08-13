<?php

declare(strict_types=1);

namespace App\Services\Driver;

use App\Enums\UserType;
use App\Events\VehicleCrewChanged;
use App\Models\Queue;
use App\Models\QueueStatus;
use App\Models\Sacco;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleUser;
use App\Services\Super\Fraud\RapidReassignDetector;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who is driving what, right now.
 *
 * SACCOs rotate drivers between vehicles daily and rarely record it, so the
 * assignment is maintained by the two moments we actually observe: onboarding
 * (an agent standing beside the matatu) and the driver's morning login. Both go
 * through here so the write path and the login lookup cannot drift apart —
 * notably on plate matching, where a space typed on a phone keyboard must not
 * produce a second vehicle.
 *
 * The invariant is a one-to-one attachment while a shift is open:
 *   - exactly one open (status = true, end_date = null) assignment per driver, and
 *   - exactly one open assignment per vehicle.
 * So a plate always resolves to a single current driver and a driver to a single
 * current vehicle. Signing in is what maintains it: today's driver taking a
 * matatu releases both their previous vehicle and the vehicle's previous driver,
 * and cancels the queue that driver left open so the new shift starts clean
 * instead of inheriting yesterday's trip — the morning rotation, recorded without
 * the SACCO having to. Superseded rows are closed rather than deleted, leaving the
 * rotation history the SACCO never kept.
 */
final class VehicleAssignment
{
    /**
     * The canonical form of a number plate: "kdq 446r" and "KDQ446R" are the
     * same vehicle, and only one of them is stored.
     */
    public static function normalisePlate(string $plate): string
    {
        return strtoupper((string) preg_replace('/\s+/u', '', trim($plate)));
    }

    /** The brand's vehicle carrying this plate, however it was typed. */
    public function findByPlate(string $plate): ?Vehicle
    {
        return $this->matching(Vehicle::with('sacco'), $plate)->first();
    }

    /**
     * The vehicle for this plate, registering it if the SACCO never has.
     *
     * A street-onboarded matatu has no owner account and no seat map yet; both
     * are the SACCO's to fill in once it claims the fleet.
     *
     * @throws PlateNotAvailable when the plate belongs to another brand.
     */
    public function resolveOrCreate(string $plate, Sacco $sacco): Vehicle
    {
        $existing = $this->findByPlate($plate);
        if ($existing !== null) {
            return $existing;
        }

        // Unscoped on purpose: the unique index we are about to hit is global,
        // so the existence check has to be too.
        if ($this->matching(Vehicle::withoutGlobalScopes(), $plate)->exists()) {
            throw PlateNotAvailable::for(self::normalisePlate($plate));
        }

        return Vehicle::create([
            'plate' => self::normalisePlate($plate),
            'sacco_id' => $sacco->id,
            'status' => true,
        ]);
    }

    /**
     * Put the driver on this vehicle, closing whatever they were on before AND
     * releasing whoever was on this vehicle before.
     *
     * The two closes are the two halves of a rotation: the driver leaves their
     * old matatu, and the matatu lets go of yesterday's driver. After this the
     * attachment is one-to-one — this driver ↔ this vehicle, nobody else on
     * either side.
     *
     * Idempotent: re-running for the vehicle they are already on returns the
     * open row rather than stacking duplicates.
     */
    public function assign(User $driver, Vehicle $vehicle): VehicleUser
    {
        // Fraud signal: the same driver attached to several vehicles inside 24h.
        // Counts distinct vehicles, so the idempotent daily re-login is ignored.
        app(RapidReassignDetector::class)->record($driver, $vehicle);

        $this->closeOpenAssignments($driver, exceptVehicleId: (int) $vehicle->id);

        // The handover: release any driver still on this vehicle, and close the
        // queue they left open so it does not silently pass to the new driver.
        $displaced = $this->releaseOtherDriversFromVehicle($vehicle, keepDriverId: (int) $driver->id);
        $queuesCancelled = $this->cancelOpenQueues($vehicle, array_keys($displaced));

        $current = $this->openAssignments($driver)
            ->where('vehicle_id', $vehicle->id)
            ->first();

        // The idempotent daily re-login: the driver signed in against the bus
        // they are already on. Nothing moved for them, so they are not told
        // anything -- but a displaced driver still is, because for THEM the
        // vehicle genuinely changed hands.
        if ($current !== null) {
            $this->announce($vehicle, null, null, $displaced, $queuesCancelled);

            return $current;
        }

        $assignment = VehicleUser::create([
            'user_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            // The vehicle's SACCO, not the driver's: the assignment records the
            // fleet the shift was worked for.
            'sacco_id' => $vehicle->sacco_id,
            'status' => true,
            'start_date' => now(),
        ]);

        $this->announce($vehicle, (int) $driver->id, (int) $assignment->id, $displaced, $queuesCancelled);

        return $assignment;
    }

    /**
     * Raise the crew-change event, but only when the crew actually changed.
     *
     * A handover used to be completely silent: the outgoing driver's assignment
     * closed and their open queue -- with its bookings and its fare -- was
     * cancelled, and they found out by opening the app to an empty screen
     * mid-shift.
     *
     * @param  array<int, int>  $displaced  driver id => closed assignment row id
     */
    private function announce(
        Vehicle $vehicle,
        ?int $assignedDriverId,
        ?int $assignedAssignmentId,
        array $displaced,
        bool $queuesCancelled,
    ): void {
        if ($assignedAssignmentId === null && $displaced === []) {
            return;
        }

        VehicleCrewChanged::dispatch(
            $vehicle,
            $assignedDriverId,
            $assignedAssignmentId,
            $displaced,
            $queuesCancelled,
        );
    }

    /** End every open assignment for this driver except the one for the given vehicle. */
    private function closeOpenAssignments(User $driver, int $exceptVehicleId): void
    {
        $this->openAssignments($driver)
            ->where('vehicle_id', '!=', $exceptVehicleId)
            ->update(['end_date' => now(), 'status' => false]);
    }

    /**
     * Release any other driver still open on this vehicle — the morning handover —
     * and report whose attachment was closed.
     *
     * Scoped to Driver-type accounts so a non-driver VehicleUser row on the vehicle
     * (an owner or admin, or a legacy crew row) is never swept up by a driver's
     * rotation; only the outgoing driver's attachment is closed.
     *
     * @return array<int, int> driver id => the VehicleUser row that was closed.
     *                         The ROW id, not the vehicle's: NotificationService
     *                         dedupes on referenceId, and a driver taken off the
     *                         same bus twice in a month must be told twice.
     */
    private function releaseOtherDriversFromVehicle(Vehicle $vehicle, int $keepDriverId): array
    {
        $rows = VehicleUser::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('status', true)
            ->whereNull('end_date')
            ->where('user_id', '!=', $keepDriverId)
            ->whereHas('user', fn ($q) => $q->where('type', UserType::Driver))
            ->get(['id', 'user_id']);

        if ($rows->isEmpty()) {
            return [];
        }

        VehicleUser::whereIn('id', $rows->pluck('id'))
            ->update(['end_date' => now(), 'status' => false]);

        return $rows->mapWithKeys(fn ($r) => [(int) $r->user_id => (int) $r->id])->all();
    }

    /**
     * Cancel any Pending/Active queue the outgoing driver(s) left open on this
     * vehicle, so the incoming driver starts a clean shift rather than inheriting
     * the previous driver's queue, bookings and fare. Scoped to the displaced
     * drivers' own queues — a queue raised for this vehicle by a dispatcher is
     * left untouched.
     *
     * Returns whether anything was actually cancelled, so the displaced driver
     * can be told the specific thing that happened to them -- losing a shift is
     * one message, losing a shift WITH passengers already booked on it is
     * another.
     *
     * @param  array<int, int>  $driverIds
     */
    private function cancelOpenQueues(Vehicle $vehicle, array $driverIds): bool
    {
        if ($driverIds === []) {
            return false;
        }

        $cancelled = QueueStatus::where('status', 'Cancelled')->first();
        if ($cancelled === null) {
            return false;
        }

        return Queue::where('vehicle_id', $vehicle->id)
            ->whereIn('user_id', $driverIds)
            ->whereHas('queue_status', fn ($q) => $q->whereIn('status', ['Pending', 'Active']))
            ->update(['queue_status_id' => $cancelled->id]) > 0;
    }

    /** @return Builder<VehicleUser> */
    private function openAssignments(User $driver)
    {
        return VehicleUser::query()
            ->where('user_id', $driver->id)
            ->where('status', true)
            ->whereNull('end_date');
    }

    /**
     * Compare plates in canonical form on both sides, so historical rows stored
     * with spaces or lower case still match.
     *
     * REPLACE/UPPER rather than a normalised column: both exist on pgsql and
     * sqlite, and the table is small enough that the unindexed scan is free.
     *
     * @param  Builder<Vehicle>  $query
     * @return Builder<Vehicle>
     */
    private function matching($query, string $plate)
    {
        return $query->whereRaw("UPPER(REPLACE(plate, ' ', '')) = ?", [self::normalisePlate($plate)]);
    }
}
