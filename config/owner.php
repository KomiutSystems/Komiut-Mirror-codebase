<?php

declare(strict_types=1);

return [

    /*
    | DEFAULT OFF, deliberately.
    |
    | Twelve real Investor accounts exist. The moment this ships, the 5-minute
    | sweep would start pushing statements at 21:00 and alerts during the day to
    | app builds that do not have those screens yet, and an FCM notification
    | shows on the phone whether or not the app handles it. Statements are still
    | BUILT AND STORED while this is off, so nothing is lost and the screens
    | work the moment the app ships — only the push is held back.
    |
    | Flip with OWNER_PUSH_ENABLED=true once the app build is out.
    */
    'push_enabled' => (bool) env('OWNER_PUSH_ENABLED', false),

];
