<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Duplicate-suppression window (minutes)
    |--------------------------------------------------------------------------
    | NotificationService treats (recipient, referenceId, title) as the identity
    | of a notification and refuses to send the same one twice. That guard exists
    | for retries — a redelivered M-Pesa webhook, a re-run reconcile — which
    | arrive seconds to a couple of minutes apart.
    |
    | Without a window the guard was permanent, and permanent is wrong: a booking
    | is a state machine a passenger can walk round more than once. Book, let the
    | hold lapse (bookings:release-expired runs EVERY MINUTE, cancelling anything
    | unpaid past booking.hold_minutes = 10), then book the same trip again —
    | second time round "Booking created" for that booking id is suppressed
    | forever, silently, with no log line. The second cancellation, likewise.
    |
    | 30 minutes is comfortably longer than the longest retry chain in the system
    | (payments:reconcile every 2 minutes) and comfortably shorter than a
    | passenger's next attempt at the same trip.
    */
    'dedupe_minutes' => (int) env('NOTIFICATION_DEDUPE_MINUTES', 30),

];
