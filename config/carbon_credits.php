<?php

declare(strict_types=1);

return [
    /*
     * Shillings of platform-paid travel that earn ONE carbon credit.
     *
     * Matatu fares are 30–150 KSh, so nothing is ever earned per-ride: the
     * accumulator carries the remainder between rides (see CarbonCreditService).
     * At 1,000 that is roughly a fortnight of commuting for one credit.
     */
    'ksh_per_credit' => (int) env('CARBON_KSH_PER_CREDIT', 1000),

    /* Kill switch. Off means no accrual and no redemption; balances are kept. */
    'enabled' => (bool) env('CARBON_CREDITS_ENABLED', true),
];
