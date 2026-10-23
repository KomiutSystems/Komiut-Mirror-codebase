<?php

declare(strict_types=1);

/**
 * Swahili push copy. Wording follows the app's own `lib/l10n/app_sw.arb` so a
 * push and the screen it opens use the same words — "haionekani tena" for a bus
 * that stopped reporting, "Kimepatikana" for a found item.
 */
return [

    'owner_statement' => [
        'title' => ':day: KES :net imebaki',
        'body' => 'Magari :buses · safari :trips · makusanyo KES :collected, matumizi KES :expenses',
    ],

    'vehicle_alert' => [
        'offline' => [
            'title' => ':plate haionekani tena',
            'body' => 'Haijatuma mahali tangu :time, ikiwa kazini.',
        ],
        'noon' => [
            'title' => ':plate: hakuna makusanyo kufikia saa sita mchana',
            'body' => 'Iko kazini tangu :since bila malipo yoyote.',
        ],
    ],

    'lost_item' => [
        'crew' => [
            'title' => 'Kitu kimesahaulika kwenye :plate',
            'body' => ':category: :description (:when)',
        ],
        'found' => [
            'title' => ':category yako imepatikana',
            'body' => 'Iko :place. Onyesha kumbukumbu :reference ili kuichukua.',
        ],
        'not_found' => [
            'title' => ':category yako haikuwa kwenye :plate',
            'body' => 'Wahudumu walikagua matatu na hawakuikuta.',
        ],
    ],

    'category' => [
        'phone' => 'Simu',
        'wallet' => 'Pochi',
        'bag' => 'Begi',
        'keys' => 'Funguo',
        'id_document' => 'Kitambulisho au hati',
        'clothing' => 'Nguo',
        'umbrella' => 'Mwavuli',
        'other' => 'Kitu',
    ],

    'when' => [
        'today' => 'leo',
        'on' => 'tarehe :date',
    ],
];
