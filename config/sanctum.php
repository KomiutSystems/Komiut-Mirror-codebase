<?php

use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    */

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort()
    ))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | This array contains the authentication guards that will be checked when
    | Sanctum is trying to authenticate a request. If none of these guards
    | are able to authenticate the request, Sanctum will use the bearer
    | token that's present on an incoming request for authentication.
    |
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes until an issued token will be
    | considered expired. If this value is null, personal access tokens do
    | not expire. This won't tweak the lifetime of first-party sessions.
    |
    */

    /*
     * A GLOBAL CEILING on token life, in minutes. 24 hours.
     *
     * This was null, which means "never expires" — and every call site that
     * issued a token (dashboard login, social login, driver login) passed no
     * explicit expiry, so every token this system had ever minted was immortal.
     * A token leaked from a phone, a proxy log or a stolen laptop stayed valid
     * forever, and nothing short of a manual delete could stop it.
     *
     * A ceiling here is defence in depth: it applies even to a future call site
     * that forgets to set one, because Sanctum treats a token with no
     * expires_at as expiring this many minutes after creation. Call sites that
     * DO set an expiry (driver sign-in) keep their own, shorter one.
     *
     * First-party session cookies are unaffected — those are governed by
     * config/session.php.
     */
    'expiration' => (int) env('SANCTUM_EXPIRATION', 1440),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware listed below as required.
    |
    */

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
    ],
];
