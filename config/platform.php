<?php

/*
| Super-admin platform console: realtime channel, per-brand alert thresholds,
| and the log sources surfaced through the API. Thresholds are config defaults
| with per-brand overrides (App\Services\Platform\Thresholds merges them), so the
| /super console never needs a redeploy to retune a burst detector.
*/

return [
    // The single cross-brand Reverb channel base name. Super admin is a platform
    // role, so it is ONE channel for every brand (brand is a field on each event,
    // not a separate channel). Base name 'super' → the client subscribes on the
    // private channel 'private-super' (echo.private('super')).
    'channel' => 'super',

    'thresholds' => [
        'defaults' => [
            'driver_login_burst' => ['count' => 5, 'window_minutes' => 15],
            'driver_rapid_reassign' => ['count' => 3, 'window_hours' => 24],
            'onboarding_velocity' => ['count' => 15, 'window_minutes' => 60],
            'partner_auth_burst' => ['count' => 5, 'window_minutes' => 15],
            'access_login_burst' => ['count' => 5, 'window_minutes' => 15],
            'sacco_dormant_days' => 14,
            'sacco_duplicate_score' => 0.75,
            'loyalty_divisor_floor' => 10,
            'loyalty_earn_failure' => ['rate' => 0.02, 'window_minutes' => 30, 'min_attempts' => 20],
            'redemption_spike_window_hours' => 6,
            'error_rate' => ['rate' => 0.05, 'window_minutes' => 5],
            'queue_backlog' => ['depth' => 1000, 'oldest_job_age_s' => 300],
            'tls_expiry_days' => 21,
            // payments:reconcile-legacy. min_count keeps a stray one or two
            // in-flight payments from crying wolf; critical_ratio is the share of
            // legacy's traffic missing here that turns the alert critical rather
            // than high. 1% is set well below the deficit actually measured on
            // 2026-08-26 (76 of 2,676 in one hour = 2.8%), so today's known loss
            // reports critical rather than being quietly graded routine.
            'legacy_payment_deficit' => ['min_count' => 5, 'critical_ratio' => 0.01],

            // Money that entered a till without reaching us, measured against
            // Safaricom's own running balance (payments:audit-till-ledger).
            //
            // min_value is an absolute figure rather than a ratio because the
            // residues this detects are small and absolute: charges and
            // reversals settling against a till produce tens of shillings a day
            // across the fleet, and alerting on those would train everyone to
            // ignore it. 500 sits above that floor and below any real outage.
            //
            // critical_value is set from the measured damage of the 2026-08-31
            // throttle incident: KES 59,671 lost in one morning. 20,000 is
            // comfortably under that, so a repeat reports critical on the day it
            // happens rather than after someone reconciles a till by hand.
            'till_ledger_deficit' => ['min_value' => 500, 'critical_value' => 20000],
        ],

        // Per-brand overrides, deep-merged onto defaults. Example:
        // 'komiut' => ['driver_login_burst' => ['count' => 8]],
        'brands' => [],
    ],

    // Server / framework log files read by the logs API (super admin only). Paths
    // are env-driven so each box points at its real locations; empty = disabled.
    'logs' => [
        'sources' => [
            'framework' => env('PLATFORM_LOG_FRAMEWORK', storage_path('logs/laravel.log')),
            'nginx_access' => env('PLATFORM_LOG_NGINX_ACCESS', '/var/log/nginx/access.log'),
            'nginx_error' => env('PLATFORM_LOG_NGINX_ERROR', '/var/log/nginx/error.log'),
            'php_fpm' => env('PLATFORM_LOG_PHP_FPM', ''),
        ],
        // Hard cap on lines returned per log-tail request.
        'max_lines' => 2000,
    ],
];
