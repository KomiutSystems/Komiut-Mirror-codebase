<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\NotificationType;
use App\Enums\UserType;
use App\Events\BookingPaid;
use App\Models\User;
use App\Models\VehicleUser;
use App\Services\Notifications\NotificationService;

/**
 * On a paid booking: tell the passenger it's confirmed, and the driver a seat
 * was booked. Type `trip` + referenceId = booking id so tapping either push
 * deep-links to the ticket.
 *
 * Runs synchronously (like EarnLoyaltyPoints), but every notify() here enqueues
 * a ShouldQueue+afterCommit notification, so no channel work touches the payment
 * transaction that raised this event.
 */
class NotifyBookingConfirmed
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(BookingPaid $event): void
    {
        $booking = $event->booking;
        $ref = (string) $booking->id;

        if ($passenger = User::find($booking->user_id)) {
            $this->notifications->dispatch(
                $passenger,
                NotificationType::Trip,
                'Booking confirmed',
                'Your booking is confirmed and paid.',
                $ref,
            );
        }

        if ($driver = $this->activeDriver($booking)) {
            $this->notifications->dispatch(
                $driver,
                NotificationType::Assignment,
                'New booking',
                'A seat on your trip has just been booked and paid.',
                $ref,
            );
        }
    }

    /** The driver currently assigned to the booking's vehicle, if any. */
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
