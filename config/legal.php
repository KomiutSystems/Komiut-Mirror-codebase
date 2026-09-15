<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Public legal pages
|--------------------------------------------------------------------------
|
| Read by App\Http\Controllers\Legal\LegalPagesController. The support
| address is optional: when unset the account-deletion page describes the
| in-app path only.
|
*/

return [
    'support_email' => env('SUPPORT_EMAIL'),
];
