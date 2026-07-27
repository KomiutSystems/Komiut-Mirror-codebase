<?php

declare(strict_types=1);

namespace App\Services\Driver;

use App\Models\Sacco;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleUser;

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
 * The invariant: exactly one open (status = true, end_date = null) assignment per
 * driver. Superseded rows are closed rather than deleted, leaving the rotation
 * history the SACCO never kept.
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
     * Put the driver on this vehicle, closing whatever they were on before.
     *
     * Idempotent: re-running for the vehicle they are already on returns the
     * open row rather than stacking duplicates.
     */
    public function assign(User $driver, Vehicle $vehicle): VehicleUser
    {
        $this->closeOpenAssignments($driver, exceptVehicleId: (int) $vehicle->id);

        $current = $this->openAssignments($driver)
            ->where('vehicle_id', $vehicle->id)
            ->first();

        if ($current !== null) {
            return $current;
        }

        return VehicleUser::create([
            'user_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            // The vehicle's SACCO, not the driver's: the assignment records the
            // fleet the shift was worked for.
            'sacco_id' => $vehicle->sacco_id,
            'status' => true,
            'start_date' => now(),
        ]);
    }

    /** End every open assignment except the one for the given vehicle. */
    private function closeOpenAssignments(User $driver, int $exceptVehicleId): void
    {
        $this->openAssignments($driver)
            ->where('vehicle_id', '!=', $exceptVehicleId)
            ->update(['end_date' => now(), 'status' => false]);
    }

    /** @return \Illuminate\Database\Eloquent\Builder<VehicleUser> */
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
     * @param  \Illuminate\Database\Eloquent\Builder<Vehicle>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Vehicle>
     */
    private function matching($query, string $plate)
    {
        return $query->whereRaw("UPPER(REPLACE(plate, ' ', '')) = ?", [self::normalisePlate($plate)]);
    }
}
