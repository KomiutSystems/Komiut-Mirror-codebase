<?php

namespace App\Providers;

use App\Events\BookingCancelled;
use App\Events\BookingCreated;
use App\Events\BookingPaid;
use App\Events\VehicleCrewChanged;
use App\Listeners\AnnouncePaymentUpdate;
use App\Listeners\EarnCarbonCredits;
use App\Listeners\EarnLoyaltyPoints;
use App\Listeners\LogScheduledTaskOutcome;
use App\Listeners\NotifyBookingCancelled;
use App\Listeners\NotifyBookingConfirmed;
use App\Listeners\NotifyBookingCreated;
use App\Listeners\NotifyCrewChanged;
use App\Services\Team\AutoCards\AutoCards;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
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
            NotifyBookingCreated::class,
            AnnouncePaymentUpdate::class,
        ],
        BookingPaid::class => [
            EarnLoyaltyPoints::class,
            EarnCarbonCredits::class,
            NotifyBookingConfirmed::class,
            AnnouncePaymentUpdate::class,
        ],
        // A handover used to be silent: the outgoing driver's shift ended and
        // their open queue -- with its bookings and its fare -- was cancelled
        // without a word. They found out by opening the app to an empty screen.
        VehicleCrewChanged::class => [
            NotifyCrewChanged::class,
        ],
        BookingCancelled::class => [
            NotifyBookingCancelled::class,
            AnnouncePaymentUpdate::class,
        ],
        // A scheduled command that FAILS must reach the operator's log. These
        // are two different facts and both are needed: Finished carries the exit
        // code of a task that ran and reported failure (the ordinary
        // `return self::FAILURE`, which does NOT throw); Failed fires only when a
        // task throws. Listening to Failed alone logged none of the 433 exit-1
        // runs of payments:reconcile-legacy -- the exact set that was invisible
        // while docker logs rendered every run as DONE.
        ScheduledTaskFinished::class => [
            LogScheduledTaskOutcome::class.'@finished',
        ],
        ScheduledTaskFailed::class => [
            LogScheduledTaskOutcome::class.'@failed',
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        // The team ops board's automatic cards: their observers and listeners.
        AutoCards::listen();
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
