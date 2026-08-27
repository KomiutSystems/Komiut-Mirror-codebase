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

    // Firebase Cloud Messaging, per brand — each brand is its own Firebase
    // project. Values are a storage-relative path to the service-account JSON
    // and the project id. An unconfigured brand simply sends no push. Only
    // komiut's file exists today; 2Safiri push needs their own project + file.
    'fcm' => [
        'komiut' => [
            'project_id' => env('KOMIUT_FCM_PROJECT_ID', 'komiut'),
            'credentials' => env('KOMIUT_FCM_CREDENTIALS', 'json/komiut-firebase-adminsdk-rq0kn-cce411b4e8.json'),
        ],
        'safiri' => [
            'project_id' => env('SAFIRI_FCM_PROJECT_ID'),
            'credentials' => env('SAFIRI_FCM_CREDENTIALS'),
        ],
        'default' => [
            'project_id' => env('KOMIUT_FCM_PROJECT_ID', 'komiut'),
            'credentials' => env('KOMIUT_FCM_CREDENTIALS', 'json/komiut-firebase-adminsdk-rq0kn-cce411b4e8.json'),
        ],
    ],

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

    // Base URL of the OLD Komiut system, read by the copy:mpesa / app:copy-cash
    // migration commands.
    //
    // Deliberately has NO default. It used to be hard-coded to
    // `https://test.komiut.co.ke`, which is not a test environment at all — it
    // and komiut.co.ke are DNS aliases for the same live host (a t2.micro in
    // ap-northeast-3 that receives real M-Pesa C2B confirmations). Those
    // commands are no longer scheduled (see app/Console/Kernel.php); leaving
    // this unset means a stray manual run aborts instead of quietly pulling
    // customer payment records across from production.
    //
    // Set LEGACY_BASE_URL only for the duration of a planned migration.
    'legacy' => [
        'base_url' => env('LEGACY_BASE_URL'),
    ],


    /*
     * Tenasms — the SMS gateway, and the only notification channel that
     * currently reaches anyone. The key and partner id were literals in
     * SendSMSController until 2026-08-27; they are still in this repository's
     * git history, so the old key must be ROTATED with the provider rather than
     * merely moved.
     *
     * THE OLD KEY IS STILL THE DEFAULT, and that is deliberate for exactly one
     * deploy. SMS carries password resets; defaulting to empty would have taken
     * those out the moment this shipped, for everyone, until someone noticed and
     * set an environment variable. The key is already public in this repo's
     * history, so leaving it here for a day changes nothing about its exposure —
     * while silently breaking sign-in recovery would change a great deal.
     *
     * TO CLOSE THIS OUT: rotate the key with Tenasms, set TENASMS_API_KEY and
     * TENASMS_PARTNER_ID in the production environment, then delete both
     * defaults below. Until that happens this is a moved credential, not a
     * secured one.
     */
    'tenasms' => [
        'url' => env('TENASMS_URL', 'https://sms.tenasms.com/api/services/sendsms'),
        'key' => env('TENASMS_API_KEY', '35cef1afb8e2cba10d4b981e6d673ee0'),
        'partner_id' => env('TENASMS_PARTNER_ID', '4052'),
        'shortcode' => env('TENASMS_SHORTCODE', 'KOMIUT'),
    ],

];
