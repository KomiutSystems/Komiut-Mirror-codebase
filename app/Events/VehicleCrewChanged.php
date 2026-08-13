<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Vehicle;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A vehicle changed hands.
 *
 * Raised by VehicleAssignment::assign() whenever the crew on a matatu actually
 * moves — never on the idempotent daily re-login, where the driver signs in
 * against the bus they are already on and nothing changes.
 *
 * `releasedAssignmentIds` is keyed by driver id, and it is the assignment ROW id
 * rather than the vehicle's, because NotificationService dedupes on
 * (recipient, referenceId, title): a driver taken off the same bus twice in a
 * month must be told twice, and a vehicle id would silence the second one.
 */
class VehicleCrewChanged
{
    use Dispatchable, SerializesModels;

    /**
     * @param  int|null  $assignedAssignmentId  the new VehicleUser row, or null when nothing changed for them
     * @param  array<int, int>  $releasedAssignmentIds  driver id => the VehicleUser row that was closed
     */
    public function __construct(
        public readonly Vehicle $vehicle,
        public readonly ?int $assignedDriverId,
        public readonly ?int $assignedAssignmentId,
        public readonly array $releasedAssignmentIds = [],
        public readonly bool $queuesCancelled = false,
    ) {}
}
