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

];
