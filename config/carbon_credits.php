<?php

declare(strict_types=1);

return [
    /*
     * Shillings of platform-paid travel that earn ONE carbon credit.
     *
     * 300 KSh, so 3,000 KSh of travel is the 10-credit milestone. Matatu fares
     * are 30–150, so nothing is earned per-ride: the accumulator carries the
     * remainder between rides (see CarbonCreditService). At 300 a daily
     * commuter crosses a credit every couple of days.
     */
    'ksh_per_credit' => (int) env('CARBON_KSH_PER_CREDIT', 300),

    /*
     * Push the passenger every time their balance crosses a multiple of this.
     * 10 credits = 3,000 KSh travelled. Milestones only — a push per credit
     * would be noise, and noise gets the app muted.
     */
    'notify_every_credits' => (int) env('CARBON_NOTIFY_EVERY_CREDITS', 10),

    /* Kill switch. Off means no accrual and no redemption; balances are kept. */
    'enabled' => (bool) env('CARBON_CREDITS_ENABLED', true),
];
