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
        // A brand answers on EVERY hostname listed here, and on no other:
        // BrandRegistry fails closed, so an unlisted host resolves to no brand
        // and the request 404s before it reaches a controller.
        //
        // KOMIUT_HOSTS is a comma-separated list and exists because two fixed
        // slots is not enough to migrate on. The money hostnames --
        // payments.komiut.com (the fleet C2B receiver, ~98%% of revenue) and
        // bankpayments.komiut.com (Co-op) -- are ordinary DNS records we own,
        // and moving one is a Route53 alias change with a 60-second TTL. But
        // Safaricom keeps POSTing to the HOSTNAME it was registered against, so
        // the instant that record points here, every confirmation arrives with
        // Host: payments.komiut.com. If that host is not listed, all of it 404s
        // and the money is gone -- the sender has a 2s timeout, no retry, and a
        // catch block that swallows the failure.
        //
        // So this list must contain a hostname BEFORE its DNS is pointed here,
        // never after. Adding one is harmless while the record still points
        // elsewhere: nothing routes to us until DNS says so.
        'hosts' => array_values(array_unique(array_filter(array_map(
            fn ($h) => trim((string) $h),
            array_merge(
                [env('KOMIUT_HOST'), env('KOMIUT_HOST_ALT')],
                explode(',', (string) env('KOMIUT_HOSTS', '')),
            ),
        )))),
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
        // A brand answers on EVERY hostname listed here, and on no other:
        // BrandRegistry fails closed, so an unlisted host resolves to no brand
        // and the request 404s before it reaches a controller.
        //
        // SAFIRI_HOSTS is a comma-separated list and exists because two fixed
        // slots is not enough to migrate on. The money hostnames --
        // payments.komiut.com (the fleet C2B receiver, ~98%% of revenue) and
        // bankpayments.komiut.com (Co-op) -- are ordinary DNS records we own,
        // and moving one is a Route53 alias change with a 60-second TTL. But
        // Safaricom keeps POSTing to the HOSTNAME it was registered against, so
        // the instant that record points here, every confirmation arrives with
        // Host: payments.komiut.com. If that host is not listed, all of it 404s
        // and the money is gone -- the sender has a 2s timeout, no retry, and a
        // catch block that swallows the failure.
        //
        // So this list must contain a hostname BEFORE its DNS is pointed here,
        // never after. Adding one is harmless while the record still points
        // elsewhere: nothing routes to us until DNS says so.
        'hosts' => array_values(array_unique(array_filter(array_map(
            fn ($h) => trim((string) $h),
            array_merge(
                [env('SAFIRI_HOST'), env('SAFIRI_HOST_ALT')],
                explode(',', (string) env('SAFIRI_HOSTS', '')),
            ),
        )))),
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
