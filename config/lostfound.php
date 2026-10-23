<?php

declare(strict_types=1);

return [

    /*
    | Where a crew's "found it" photo is stored. Must be a PRIVATE disk: these
    | are photos of passengers' belongings and are served only through the
    | signed route on LostItemsController, never by public URL.
    */
    'photo_disk' => env('LOSTFOUND_PHOTO_DISK', 'local'),

    /*
    | How long after a report closes its photo is deleted (§3.5). Kept a little
    | past the collection window so a dispute still has the evidence.
    */
    'photo_retention_days' => (int) env('LOSTFOUND_PHOTO_RETENTION_DAYS', 30),

];
