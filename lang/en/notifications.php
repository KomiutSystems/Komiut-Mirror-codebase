<?php

declare(strict_types=1);

/**
 * Push copy, rendered per device language (PlatformNotification::fcmFor).
 * The in-app and SMS copies stay English and are built at the call site.
 */
return [

    'owner_statement' => [
        'title' => ':day: KES :net left',
        'body' => ':buses buses · :trips trips · KES :collected collected, KES :expenses expenses',
    ],

    'vehicle_alert' => [
        'offline' => [
            'title' => ':plate went offline',
            'body' => 'No location since :time, during its shift.',
        ],
        'noon' => [
            'title' => ':plate: no collections by noon',
            'body' => 'On shift since :since with no payment yet.',
        ],
    ],

    'lost_item' => [
        'crew' => [
            'title' => 'Lost item on :plate',
            'body' => ':category: :description (:when)',
        ],
        'found' => [
            'title' => 'Your :category was found',
            'body' => 'It is at :place. Show reference :reference to collect it.',
        ],
        'not_found' => [
            'title' => 'Your :category was not on :plate',
            'body' => 'The crew checked the matatu and did not find it.',
        ],
    ],

    // Lowercase: these read inside a sentence ("Your phone was found").
    'category' => [
        'phone' => 'phone',
        'wallet' => 'wallet',
        'bag' => 'bag',
        'keys' => 'keys',
        'id_document' => 'ID or document',
        'clothing' => 'clothing',
        'umbrella' => 'umbrella',
        'other' => 'item',
    ],

    'when' => [
        'today' => 'today',
        'on' => 'on :date',
    ],
];
