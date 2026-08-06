<?php

declare(strict_types=1);

namespace App\Services\Cache;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;

/**
 * Cache keys that carry the same boundary BrandScope enforces at query time.
 *
 * Caching a brand-scoped read under a brand-agnostic key would hand one brand
 * another brand's rows — the isolation would hold in SQL and then leak through
 * the cache. So every key is stamped with the audience the query ran as, and
 * this class mirrors BrandScope::apply() branch for branch:
 *
 *   no brand in Context  -> BrandScope does not filter  -> segment "_nobrand"
 *   super admin          -> BrandScope returns early    -> segment "super"
 *   anyone else          -> BrandScope filters by brand -> segment "<brand>"
 *
 * If BrandScope ever changes how it decides, this must change with it; a
 * disagreement between the two is a cross-brand data leak, not a cache miss.
 *
 * INVALIDATION is a version counter rather than cache tags, because tags are
 * unsupported on the `file` store and CACHE_DRIVER defaults to file — tagged
 * flushes would throw the moment someone deploys without Redis. Bumping the
 * version orphans every old key instead of deleting it; the orphans expire on
 * their own TTL.
 *
 * bust() deliberately invalidates a bucket across ALL brands and audiences.
 * Reference data is global (a new gender is not brand-owned), and
 * over-invalidating only costs a recompute, whereas under-invalidating serves
 * data the user just changed and looks like the write was lost.
 */
final class ScopedCache
{
    /** Sentinel for "no brand resolved" — must not collide with a real brand key. */
    private const NO_BRAND = '_nobrand';

    /**
     * Remember a value under a key scoped to the current brand and audience.
     *
     * @param  string  $bucket  invalidation unit, e.g. "reference:genders"
     * @param  array<string,mixed>|string  $parts  every input that changes the result
     */
    public static function remember(string $bucket, array|string $parts, int $ttl, Closure $callback): mixed
    {
        return Cache::remember(self::key($bucket, $parts), $ttl, $callback);
    }

    /**
     * Invalidate a bucket for every brand and audience at once.
     *
     * Readers default the version to 1, so seeding at 2 is itself a bust when
     * no counter exists yet. increment() is atomic on Redis, which matters when
     * two writes land together. The counter is stored without a TTL: if it were
     * evicted the version would reset and pre-bust entries could resurface.
     */
    public static function bust(string $bucket): void
    {
        $key = self::versionKey($bucket);

        if (! Cache::add($key, 2, null)) {
            Cache::increment($key);
        }
    }

    /** @param  array<string,mixed>|string  $parts */
    public static function key(string $bucket, array|string $parts): string
    {
        return implode(':', [
            'sc',
            $bucket,
            'v'.self::version($bucket),
            self::segment(),
            self::fingerprint($parts),
        ]);
    }

    /**
     * The audience segment, derived exactly as BrandScope derives it.
     *
     * Read from Context (the request's brand) and Auth (the super-admin
     * exemption) — never from a route group or permission check, which could
     * disagree with the scope and silently widen what a cached entry is shared
     * with.
     */
    private static function segment(): string
    {
        if (! Context::has('brand')) {
            return self::NO_BRAND;
        }

        $user = Auth::user();
        if ($user instanceof User && $user->isSuperAdmin()) {
            return 'super';
        }

        return 'brand:'.(string) Context::get('brand');
    }

    private static function version(string $bucket): int
    {
        return (int) Cache::get(self::versionKey($bucket), 1);
    }

    private static function versionKey(string $bucket): string
    {
        return 'sc:ver:'.$bucket;
    }

    /**
     * Hash the inputs so semantically identical requests share an entry.
     * Sorted by key, so ?status=1&q=x and ?q=x&status=1 are one entry.
     *
     * @param  array<string,mixed>|string  $parts
     */
    private static function fingerprint(array|string $parts): string
    {
        if (is_string($parts)) {
            return $parts === '' ? 'all' : md5($parts);
        }

        ksort($parts);

        return md5((string) json_encode($parts));
    }
}
