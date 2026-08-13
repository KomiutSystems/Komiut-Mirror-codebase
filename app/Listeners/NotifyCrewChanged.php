<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\NotificationType;
use App\Events\VehicleCrewChanged;
use App\Models\User;
use App\Services\Notifications\NotificationService;

/**
 * Tell a driver when the matatu under them changes.
 *
 * The releases are the point. A handover closes the outgoing driver's
 * assignment AND cancels the queue they left open, with its bookings and its
 * fare — and until now it did that silently. They found out by opening the app
 * to an empty screen, mid-shift, with passengers already seated.
 *
 * The assignment side is quieter but still worth sending: a driver who signed
 * in themselves already knows, but a SACCO admin can attach a driver to a
 * vehicle from the dashboard and that driver has no other way to hear about it.
 *
 * Runs synchronously like NotifyBookingConfirmed; every dispatch() here enqueues
 * a ShouldQueue + afterCommit notification, so no channel work happens inside
 * the login transaction that raised the event.
 */
class NotifyCrewChanged
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(VehicleCrewChanged $event): void
    {
        $plate = (string) $event->vehicle->plate;

        foreach ($event->releasedAssignmentIds as $driverId => $assignmentId) {
            $driver = User::withoutGlobalScopes()->find($driverId);
            if ($driver === null) {
                continue;
            }

            $this->notifications->dispatch(
                $driver,
                NotificationType::Assignment,
                'You are off '.$plate,
                $event->queuesCancelled
                    ? 'Another driver has taken '.$plate.'. Your open trip has been cancelled.'
                    : 'Another driver has taken '.$plate.'.',
                // The closed assignment row: unique per handover, so being taken
                // off the same bus again next week is not silently deduped.
                (string) $assignmentId,
                $event->vehicle->sacco_id === null ? null : (string) $event->vehicle->sacco_id,
            );
        }

        if ($event->assignedDriverId === null || $event->assignedAssignmentId === null) {
            return;
        }

        $driver = User::withoutGlobalScopes()->find($event->assignedDriverId);
        if ($driver === null) {
            return;
        }

        $this->notifications->dispatch(
            $driver,
            NotificationType::Assignment,
            'You are on '.$plate,
            'You have been assigned to '.$plate.'.',
            (string) $event->assignedAssignmentId,
            $event->vehicle->sacco_id === null ? null : (string) $event->vehicle->sacco_id,
        );
    }
}
