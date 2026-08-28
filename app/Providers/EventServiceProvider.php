<?php

namespace App\Providers;

use App\Events\BookingCancelled;
use App\Events\BookingCreated;
use App\Events\BookingPaid;
use App\Events\VehicleCrewChanged;
use App\Listeners\EarnLoyaltyPoints;
use App\Listeners\NotifyBookingConfirmed;
use App\Listeners\NotifyCrewChanged;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        // shouldDiscoverEvents() is false (see below), so a listener that is not
        // in this array is simply never called. Every new listener must be added
        // here — a silent no-op is exactly how the booking lifecycle stayed mute.
        BookingCreated::class => [
            \App\Listeners\NotifyBookingCreated::class,
        ],
        BookingPaid::class => [
            EarnLoyaltyPoints::class,
            NotifyBookingConfirmed::class,
        ],
        // A handover used to be silent: the outgoing driver's shift ended and
        // their open queue -- with its bookings and its fare -- was cancelled
        // without a word. They found out by opening the app to an empty screen.
        VehicleCrewChanged::class => [
            NotifyCrewChanged::class,
        ],
        BookingCancelled::class => [
            \App\Listeners\NotifyBookingCancelled::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
