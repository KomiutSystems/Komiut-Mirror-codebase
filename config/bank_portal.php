<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Bank partner portal
|--------------------------------------------------------------------------
|
| A shared link, handed to a partner bank, listing the drivers who asked for
| help opening an account. The bank is identified BY ITS PASSWORD: each partner
| gets its own, and that key alone decides which brand's leads they can read.
| There is no account to provision and no cross-bank selector to get wrong —
| NCBA's key can only ever return komiut drivers.
|
| Unset passwords fail closed (see BankPartnerAuth): a blank env value must
| never become a blank-password login. Set these in SSM, never in git.
|
*/

return [

    'partners' => [

        'ncba' => [
            'password' => env('BANK_PORTAL_NCBA_PASSWORD'),
            'brand' => 'komiut',
            'label' => 'NCBA Bank',
        ],

        'coop' => [
            'password' => env('BANK_PORTAL_COOP_PASSWORD'),
            'brand' => 'safiri',
            'label' => 'Co-operative Bank',
        ],

    ],

    /*
    | Rows per page on the list endpoint. The export ignores it and streams the
    | whole set, so a bank never has to paginate a spreadsheet by hand.
    */
    'per_page' => 50,

];
