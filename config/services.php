<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Passenger social sign-in (mobile). Google works with laravel/socialite
    // out of the box; Apple additionally needs the socialiteproviders/apple
    // driver registered (see SocialAuthController).
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID'),
        'client_secret' => env('APPLE_CLIENT_SECRET'),
        'redirect' => env('APPLE_REDIRECT_URI'),
    ],

    // Bank-issued credentials NCBA posts on the M-Pesa confirmation webhook.
    // Provisioned per the NCBA collection agreement — NEVER hard-code them (the
    // old password was committed to source). Unset => the endpoint rejects every
    // call (fail closed) until the real credentials are placed in SSM.
    'ncba' => [
        'username' => env('NCBA_USERNAME'),
        'password' => env('NCBA_PASSWORD'),
        'secret' => env('NCBA_SECRET'),
    ],

];
