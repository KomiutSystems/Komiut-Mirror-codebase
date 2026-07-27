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
        // Google OAuth client ids this brand's app may present. An ID token is
        // only accepted when its `aud` is one of these, which is what stops a
        // token minted for someone else's Google app being replayed here.
        // Each brand has its own Google Cloud project (own consent screen), and
        // a native sign-in may carry either the web or the platform client id.
        'google_client_ids' => array_values(array_filter([
            env('KOMIUT_GOOGLE_WEB_CLIENT_ID'),
            env('KOMIUT_GOOGLE_ANDROID_CLIENT_ID'),
            env('KOMIUT_GOOGLE_IOS_CLIENT_ID'),
        ])),
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
        // See the komiut entry: accepted `aud` values for this brand's own
        // Google Cloud project.
        'google_client_ids' => array_values(array_filter([
            env('SAFIRI_GOOGLE_WEB_CLIENT_ID'),
            env('SAFIRI_GOOGLE_ANDROID_CLIENT_ID'),
            env('SAFIRI_GOOGLE_IOS_CLIENT_ID'),
        ])),
    ],

];
