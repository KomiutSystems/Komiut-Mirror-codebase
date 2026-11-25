<?php

declare(strict_types=1);

return [

    /*
    | How far a passenger may be from a stop and still board there.
    |
    | "Pick as you go" means boarding at the stops along a route, not anywhere
    | at the roadside. Reservations without an explicit pick-up stop snap to the
    | nearest one and are REFUSED beyond this radius, rather than snapped from
    | any distance and silently charged from the start of the route.
    |
    | A kilometre is a walk to a stop, not a different neighbourhood. Tune it
    | here rather than in the controller: the right number is an operational
    | question about how far apart the stages actually are.
    */
    'max_pickup_km' => (float) env('BOOKING_MAX_PICKUP_KM', 1.0),

    /*
    |--------------------------------------------------------------------------
    | Unpaid seat-hold window (minutes)
    |--------------------------------------------------------------------------
    | A booking reserves its seats the moment it is created, before payment.
    | If it isn't paid within this window it is treated as expired: it no
    | longer occupies its seats (at read time) and the release sweep flips it
    | inactive. Keep this short enough that abandoned bookings free seats fast.
    */
    'hold_minutes' => (int) env('BOOKING_HOLD_MINUTES', 10),

    /*
    |--------------------------------------------------------------------------
    | Fare cache TTL (seconds)
    |--------------------------------------------------------------------------
    | Fares change rarely but are read on every price preview and every
    | booking, so the resolver caches one bundle per (sacco, route). The cache
    | is invalidated explicitly whenever a SACCO edits a fare, so this TTL is
    | only a backstop.
    */
    'fare_cache_ttl' => (int) env('FARE_CACHE_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Default vehicle capacity (seats)
    |--------------------------------------------------------------------------
    | A vehicle onboarded off the street has no seat layout yet, so its seat
    | count reads 0 and the driver screen would show "no capacity" for a matatu
    | that is actively taking bookings. Until the SACCO enters the real layout,
    | fall back to this (a standard 14-seater) so a number is always shown; the
    | capacity response flags `seats_configured: false` so the app can prompt.
    */
    'default_seats' => (int) env('BOOKING_DEFAULT_SEATS', 14),

    /*
    |--------------------------------------------------------------------------
    | Arrival alert radius (metres)
    |--------------------------------------------------------------------------
    | A paid passenger waiting for their bus is pushed "your bus is arriving"
    | once, the first time the bus's live position comes within this distance
    | of their pickup point. See App\Services\Booking\ArrivalAlerts.
    */
    'arrival_alert_metres' => (int) env('BOOKING_ARRIVAL_ALERT_METRES', 200),

    /*
    | Seconds after a trip starts during which the DRIVER's "end trip" is
    | refused as an accidental second tap (DriverTripController::end). The
    | app's one button turns from Start trip into End trip under the finger.
    | 0 switches the guard off. The dashboard's end is not affected.
    */
    'end_trip_grace_seconds' => (int) env('BOOKING_END_TRIP_GRACE_SECONDS', 60),

    /*
    | School buses: parents are told when the bus is about this many seconds
    | from their stop (ArrivalAlerts), estimated from the bus's own smoothed
    | speed (vehicle_locations.speed_kmh), the default until it has one, never
    | below the minimum, and never further away than the cap.
    */
    'school_approach_seconds' => (int) env('BOOKING_SCHOOL_APPROACH_SECONDS', 120),
    'school_default_speed_kmh' => (float) env('BOOKING_SCHOOL_DEFAULT_SPEED_KMH', 20),
    'school_min_speed_kmh' => (float) env('BOOKING_SCHOOL_MIN_SPEED_KMH', 10),
    'school_approach_max_metres' => (int) env('BOOKING_SCHOOL_APPROACH_MAX_METRES', 3000),
];
