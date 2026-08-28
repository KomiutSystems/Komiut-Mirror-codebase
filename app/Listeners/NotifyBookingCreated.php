<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\NotificationType;
use App\Enums\UserType;
use App\Events\BookingCreated;
use App\Models\User;
use App\Models\VehicleUser;
use App\Services\Notifications\NotificationService;

/**
 * On a new (unpaid) booking: tell the passenger their seats are held and for how
 * long, and tell the assigned driver someone is waiting.
 *
 * The passenger gets SMS as well as the in-app row. That is the whole point of
 * this listener: the hold is only booking.hold_minutes = 10 minutes long and
 * bookings:release-expired sweeps every minute, so a passenger who never sees
 * the reminder loses the seat. Push cannot carry that message — 6 device tokens
 * exist across 4 users out of 6,808, so 99.9% of passengers would be told
 * nothing at all.
 *
 * The driver does NOT get SMS. Crew are notified on a per-booking cadence all
 * day; at one credit per booking that is a running cost with no deadline behind
 * it, and BookARideQueuesAPIController::notifyCrew already pushes to them.
 *
 * Runs synchronously (like NotifyBookingConfirmed), but every notify() enqueues
 * a ShouldQueue + afterCommit notification, so no channel work runs inside the
 * booking transaction that raised this.
 */
class NotifyBookingCreated
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(BookingCreated $event): void
    {
        $booking = $event->booking;
        $ref = (string) $booking->id;
        $holdMinutes = (int) config('booking.hold_minutes', 10);

        if ($passenger = User::find($booking->user_id)) {
            $this->notifications->dispatch(
                $passenger,
                // Trip + a referenceId is the ONLY combination the mobile app
                // deep-links (to the ticket); every other type opens the list.
                NotificationType::Trip,
                'Booking created',
                sprintf(
                    'Your seat is held for %d minutes. Pay KES %s to confirm booking #%s.',
                    $holdMinutes,
                    number_format((float) $booking->amount, 0),
                    $ref,
                ),
                $ref,
                channels: ['database', 'broadcast', 'fcm', 'sms'],
            );
        }

        if ($driver = $this->activeDriver($booking)) {
            $this->notifications->dispatch(
                $driver,
                NotificationType::Assignment,
                'Seat held',
                'A passenger has booked a seat on your trip and is paying now.',
                $ref,
            );
        }
    }

    /**
     * The driver currently assigned to the booking's vehicle, if any.
     *
     * An OPEN assignment is status = true AND end_date IS NULL — vehicle_users
     * keeps closed history rows for the same (vehicle, user), so dropping either
     * condition would notify a driver who left the vehicle months ago.
     * vehicles.user_id is NOT usable here: it records whoever last saved the row
     * (168 of NICCO's 180 buses point at the migration account).
     */
    private function activeDriver(\App\Models\Booking $booking): ?User
    {
        $vehicleId = $booking->queue?->vehicle_id;
        if (! $vehicleId) {
            return null;
        }

        $assignment = VehicleUser::with('user')
            ->where('vehicle_id', $vehicleId)
            ->where('status', true)
            ->whereNull('end_date')
            ->whereHas('user', fn ($q) => $q->where('type', UserType::Driver))
            ->latest('id')
            ->first();

        return $assignment?->user;
    }
}
