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
    /**
     * THE FLOAT ACCOUNT — a Komiut-owned collection shortcode, and the single
     * gate on every rail that can credit a wallet.
     *
     * THIS IS A SECURITY CONTROL, NOT A FEATURE FLAG. A wallet may only be
     * credited by money that actually arrived in Komiut's own account. Every
     * M-Pesa credential in this system is per-SACCO (`mpesa_payment_settings`,
     * resolved booking -> vehicle -> SACCO), so without this set there is NO
     * account a top-up could land in. If a wallet could be credited from a
     * payment on a SACCO's till, a passenger could type their wallet number
     * into any bus's paybill and be credited with that SACCO's money.
     *
     * So: unset means no rail credits anything, and the ingest refuses rather
     * than guessing. Needs provisioning from Safaricom (a Komiut paybill plus a
     * Daraja app over it) before `mpesa` can go live, and an NCBA
     * account-number scheme on 880100 before `ncba` can.
     */
    'float' => [
        'short_code' => env('WALLET_FLOAT_SHORT_CODE'),

        /**
         * HOW WE KNOW A CONFIRMATION REALLY CAME FROM SAFARICOM.
         *
         * `BusinessShortCode` arrives in the REQUEST BODY, and the generic C2B
         * receiver (`/api/confirmation/{id}`) is unauthenticated -- it has to
         * be, Safaricom presents no credential, and 1.3M real confirmations
         * have been delivered to it. That is tolerable for a fare: a forged
         * POST writes a visible, fixable transactions row. It is NOT tolerable
         * for a wallet, where the same forgery mints withdrawable balance out of
         * nothing. Reading the attacker's own body field to decide "this money
         * landed in Komiut's account" is not a gate at all.
         *
         * So a wallet is credited from a confirmation ONLY when it arrives on
         * the float's own secret URL -- the one we register with Safaricom for
         * the float shortcode and nobody else can guess:
         *
         *     POST /api/wallet/c2b/{secret}/confirmation
         *
         * UNSET MEANS NO CONFIRMATION CAN EVER CREDIT A WALLET. Deliberately
         * the opposite default to services.coop.source_ips, where unset means
         * open: refusing a real payment is recoverable by hand, minting balance
         * from a forged one is not.
         *
         * `source_ips` is defence in depth on top, not instead: Safaricom's
         * egress addresses, comma-separated. Unset skips the check, because a
         * wrong value here would refuse real money and the secret is already
         * doing the authenticating.
         */
        'confirmation_secret' => env('WALLET_FLOAT_CONFIRMATION_SECRET'),
        'source_ips' => array_values(array_filter(array_map(
            fn ($ip) => trim((string) $ip),
            explode(',', (string) env('WALLET_FLOAT_SOURCE_IPS', '')),
        ))),
        'consumer_key' => env('WALLET_FLOAT_CONSUMER_KEY'),
        'consumer_secret' => env('WALLET_FLOAT_CONSUMER_SECRET'),
        'pass_key' => env('WALLET_FLOAT_PASS_KEY'),
    ],

    /**
     * PAYING SACCOs OUT OF THE FLOAT.
     *
     * `rail` picks how the money leaves:
     *
     *   manual   (the default, and the only one that works today) nothing is
     *            sent automatically. A settlement is approved, somebody pays it
     *            by bank transfer, and the reference is recorded against the
     *            row. Honest, auditable, and it needs no credential we do not
     *            have.
     *   daraja_b2b  Safaricom Business-to-Business from a Komiut shortcode to
     *            the SACCO's paybill. Needs the float shortcode AND an
     *            initiator name AND a SecurityCredential -- the initiator
     *            password RSA-encrypted with Safaricom's production
     *            certificate, which is a file we do not hold. Unset means the
     *            rail reports itself unavailable and refuses rather than
     *            guessing.
     *
     * NCBA HAS NO PAYOUT RAIL AT ALL. App\Services\Ncba\NcbaStkClient is the
     * whole integration and it is three collection calls -- auth/token,
     * stk-push/initiate, stk-push/query. There is no transfer, no balance and
     * no statement call, so a Komiut float held at NCBA can only be paid out by
     * a human until the bank provides one. That is the single biggest open
     * question on this feature.
     */
    'payout' => [
        'rail' => env('WALLET_PAYOUT_RAIL', 'manual'),

        'daraja_b2b' => [
            'initiator' => env('WALLET_PAYOUT_INITIATOR'),
            'security_credential' => env('WALLET_PAYOUT_SECURITY_CREDENTIAL'),
            // Where Safaricom posts the outcome. B2B is asynchronous: the POST
            // only says it was accepted.
            'result_url' => env('WALLET_PAYOUT_RESULT_URL'),
            'timeout_url' => env('WALLET_PAYOUT_TIMEOUT_URL'),
        ],
    ],

    /**
     * How long a pending top-up stays pending before it is called timed_out.
     *
     * An STK prompt dies on the handset in about a minute, but the money can
     * still land afterwards, so this is deliberately far longer than the prompt
     * -- a top-up marked failed while its payment is in flight is how a
     * passenger ends up paying for nothing. The reconciliation sweep is what
     * catches a payment that lands after the deadline.
     */
    'top_up_expiry_minutes' => (int) env('WALLET_TOP_UP_EXPIRY_MINUTES', 15),

    'top_up_sources' => [
        ['id' => 'mpesa', 'available' => (bool) env('WALLET_SOURCE_MPESA', false)],
        ['id' => 'ncba', 'available' => (bool) env('WALLET_SOURCE_NCBA', false)],
    ],
];
