<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\PlatformThreshold;
use Illuminate\Support\Facades\Cache;

/**
 * Per-brand alert thresholds. Emitters ask
 * `Thresholds::get('komiut', 'driver_login_burst')` instead of hard-coding
 * numbers, so the /super settings page can retune a detector without a deploy.
 *
 * Four layers, each overriding the one before:
 *
 *   1. config defaults          platform.thresholds.defaults
 *   2. config per-brand         platform.thresholds.brands.<brand>
 *   3. stored platform-wide     platform_thresholds rows with brand = null
 *   4. stored per-brand         platform_thresholds rows for this brand
 *
 * Config stays the source of DEFAULTS and the table only ever overrides it, so
 * truncating the table restores shipped behaviour rather than leaving detectors
 * with no thresholds at all.
 *
 * Array thresholds ({count, window_minutes}) merge key-by-key, so overriding
 * `count` alone keeps the shipped window instead of blanking it.
 */
class Thresholds
{
    /** Rows are read on every detector call; the console writes rarely. */
    private const CACHE_TTL = 300;

    /**
     * The resolved threshold for a brand + key. Scalars return as-is; array
     * thresholds return the override merged onto the default.
     */
    public static function get(?string $brand, string $key): mixed
    {
        return self::all($brand)[$key] ?? null;
    }

    /** All thresholds for a brand, fully resolved — this is what the settings UI renders. */
    public static function all(?string $brand): array
    {
        $out = (array) config('platform.thresholds.defaults', []);

        $layers = [
            $brand !== null ? (array) config("platform.thresholds.brands.$brand", []) : [],
            self::stored(null),
            $brand !== null ? self::stored($brand) : [],
        ];

        foreach ($layers as $layer) {
            foreach ($layer as $key => $value) {
                // Only keys that exist in the defaults are resolvable: an unknown
                // key would be a threshold nothing reads, and surfacing it in the
                // settings UI would imply it does something.
                if (! array_key_exists($key, $out)) {
                    continue;
                }

                $out[$key] = is_array($out[$key]) && is_array($value)
                    ? array_replace($out[$key], $value)
                    : $value;
            }
        }

        return $out;
    }

    /** The keys a caller is allowed to override — exactly the shipped defaults. */
    public static function keys(): array
    {
        return array_keys((array) config('platform.thresholds.defaults', []));
    }

    /** Stored overrides for one scope. `null` means the platform-wide rows. */
    private static function stored(?string $brand): array
    {
        return Cache::remember(
            self::cacheKey($brand),
            self::CACHE_TTL,
            fn (): array => PlatformThreshold::query()
                ->when($brand === null, fn ($q) => $q->whereNull('brand'))
                ->when($brand !== null, fn ($q) => $q->where('brand', $brand))
                ->pluck('value', 'key')
                // Values are stored wrapped as {"v": ...} so a scalar threshold
                // and a shaped one round-trip through one json column identically.
                ->map(fn ($v) => is_array($v) && array_key_exists('v', $v) ? $v['v'] : $v)
                ->all(),
        );
    }

    /** Wrap for storage — see the note in stored(). */
    public static function wrap(mixed $value): array
    {
        return ['v' => $value];
    }

    public static function bust(?string $brand): void
    {
        Cache::forget(self::cacheKey($brand));
        Cache::forget(self::cacheKey(null));
    }

    private static function cacheKey(?string $brand): string
    {
        return 'platform:thresholds:'.($brand ?? '_platform');
    }
}
