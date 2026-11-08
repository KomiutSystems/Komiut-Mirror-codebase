<?php

declare(strict_types=1);

/**
 * Passenger wallet limits and PIN policy.
 *
 * EVERY VALUE HERE IS IN WHOLE SHILLINGS, because that is the unit the mobile
 * app speaks and the unit an operator reasons in. The store is cents
 * (App\Support\Shillings) -- conversion happens once, at the service boundary.
 *
 * The app ships its own fallbacks for these (wallet_data.dart's
 * WalletLimits.fromJson defaults to 50 / 20000 / 50000 / 300). Those are a
 * cold-start convenience ONLY: a client cannot be trusted to enforce a money
 * limit, so every one of them is checked again server-side. If the two ever
 * disagree, this file wins and the app's own number is cosmetic.
 */
return [
    'limits' => [
        'min_top_up' => (int) env('WALLET_MIN_TOP_UP', 50),
        'max_top_up' => (int) env('WALLET_MAX_TOP_UP', 20000),

        // The ceiling on a held balance. This is the float liability per
        // passenger, so it is a risk control, not a UX preference.
        'max_balance' => (int) env('WALLET_MAX_BALANCE', 50000),

        // The highest a passenger may raise their own no-PIN tap ceiling to.
        'max_tap_limit' => (int) env('WALLET_MAX_TAP_LIMIT', 300),
    ],

    // What a brand-new wallet taps without a PIN until the passenger changes it.
    'default_tap_limit' => (int) env('WALLET_DEFAULT_TAP_LIMIT', 100),

    'pin' => [
        'length' => 4,

        // Matches the app's own PreviewWalletApi, which locks on the 5th wrong
        // try -- so the copy it shows ("2 tries left") stays truthful.
        'max_attempts' => (int) env('WALLET_PIN_MAX_ATTEMPTS', 5),
        'lock_minutes' => (int) env('WALLET_PIN_LOCK_MINUTES', 15),
    ],

    /**
     * The rails a passenger may load from, in the shape GET /wallet returns:
     * the app keeps only entries with `available: true` and reads `id`.
     *
     * `ncba` is present and OFF. That is deliberate and not a placeholder: the
     * Komiut float is an NCBA account, but the NCBA integration this codebase
     * holds is collection-only -- auth/token, stk-push/initiate,
     * stk-push/query (App\Services\Ncba\NcbaStkClient) -- and the STK
     * credentials are not provisioned yet. Flipping this on before
     * services.ncba_stk is set would advertise a rail that cannot take money.
     */
    'top_up_sources' => [
        ['id' => 'mpesa', 'available' => (bool) env('WALLET_SOURCE_MPESA', false)],
        ['id' => 'ncba', 'available' => (bool) env('WALLET_SOURCE_NCBA', false)],
    ],
];
