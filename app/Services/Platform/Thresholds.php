<?php

declare(strict_types=1);

namespace App\Services\Platform;

/**
 * Per-brand alert thresholds: config defaults deep-merged with per-brand
 * overrides. Emitters ask `Thresholds::get('komiut', 'driver_login_burst')`
 * instead of hard-coding numbers, so the /super settings page can retune without
 * a deploy (overrides persist to config('platform.thresholds.brands') via the
 * settings endpoint, or ship in config).
 */
class Thresholds
{
    /**
     * The resolved threshold for a brand + key. Scalars return as-is; array
     * thresholds (count/window) return the override merged onto the default.
     */
    public static function get(?string $brand, string $key): mixed
    {
        $default = config("platform.thresholds.defaults.$key");
        $override = $brand !== null ? config("platform.thresholds.brands.$brand.$key") : null;

        if ($override === null) {
            return $default;
        }
        if (is_array($default) && is_array($override)) {
            return array_replace($default, $override);
        }

        return $override;
    }

    /** All thresholds for a brand (defaults with overrides applied) — for the settings UI. */
    public static function all(?string $brand): array
    {
        $defaults = (array) config('platform.thresholds.defaults', []);
        $overrides = $brand !== null ? (array) config("platform.thresholds.brands.$brand", []) : [];

        $out = [];
        foreach ($defaults as $key => $default) {
            $out[$key] = is_array($default) && isset($overrides[$key]) && is_array($overrides[$key])
                ? array_replace($default, $overrides[$key])
                : ($overrides[$key] ?? $default);
        }

        return $out;
    }
}
