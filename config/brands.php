<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| White-label brands
|--------------------------------------------------------------------------
|
| One codebase, one deployment, one database *per brand*. The brand is
| resolved per request — by hostname on the web, by the X-App-Key header for
| the mobile apps. Brands are static and known at deploy time, so this is a
| plain config array: no tenants table, no lookups, no cache.
|
| There is deliberately NO default brand. An unrecognised host or app key must
| fail closed, because resolving to the wrong brand means reading another
| brand's database.
|
| The keys of `features` are the backing values of App\Brands\Feature.
|
*/

return [

    'komiut' => [
        'name' => 'Komiut',
        'hosts' => array_values(array_filter([
            env('KOMIUT_HOST'),
            env('KOMIUT_HOST_ALT'),
        ])),
        'app_key' => env('KOMIUT_APP_KEY'),
        'features' => [
            'parcels' => (bool) env('KOMIUT_FEATURE_PARCELS', true),
            'carpool' => (bool) env('KOMIUT_FEATURE_CARPOOL', false),
            'wallet' => (bool) env('KOMIUT_FEATURE_WALLET', true),
            'bookings' => (bool) env('KOMIUT_FEATURE_BOOKINGS', true),
            'loyalty' => (bool) env('KOMIUT_FEATURE_LOYALTY', false),
        ],
        'session' => [
            'cookie' => env('KOMIUT_SESSION_COOKIE', 'komiut_session'),
            'domain' => env('KOMIUT_SESSION_DOMAIN'),
        ],
    ],

    'safiri' => [
        'name' => '2Safiri',
        'hosts' => array_values(array_filter([
            env('SAFIRI_HOST'),
            env('SAFIRI_HOST_ALT'),
        ])),
        'app_key' => env('SAFIRI_APP_KEY'),
        'features' => [
            'parcels' => (bool) env('SAFIRI_FEATURE_PARCELS', false),
            'carpool' => (bool) env('SAFIRI_FEATURE_CARPOOL', true),
            'wallet' => (bool) env('SAFIRI_FEATURE_WALLET', true),
            'bookings' => (bool) env('SAFIRI_FEATURE_BOOKINGS', true),
            'loyalty' => (bool) env('SAFIRI_FEATURE_LOYALTY', false),
        ],
        'session' => [
            'cookie' => env('SAFIRI_SESSION_COOKIE', 'safiri_session'),
            'domain' => env('SAFIRI_SESSION_DOMAIN'),
        ],
    ],

];
