<?php

declare(strict_types=1);

namespace App\Brands;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

/**
 * Activates a resolved Brand for the current lifecycle.
 *
 * This is the single place that mutates lifecycle state, so the HTTP middleware,
 * the queue Context hook, and the `brand:each` command all activate a brand the
 * same way. It does four things:
 *
 *   1. Binds the Brand into the container so `app(Brand::class)` returns it.
 *   2. Points the default DB connection at the brand's own database.
 *   3. Records the brand key in Context — which is what carries the brand across
 *      the HTTP -> queued-job boundary.
 *   4. Isolates the shared Redis cache/session keyspace so one brand can never
 *      read another's cached values.
 */
final readonly class BrandContext
{
    public function __construct(private Application $app) {}

    public function apply(Brand $brand): void
    {
        // Overrides the scoped Brand binding for this lifecycle. Because the
        // binding is registered with scoped(), this instance is flushed at the
        // next lifecycle boundary (new queue job / Octane request).
        $this->app->instance(Brand::class, $brand);

        DB::setDefaultConnection($brand->connection);

        Context::add('brand', $brand->key);

        // Both brands share one Redis. Prefix the cache keyspace so a lookup for
        // one brand cannot return another's value. Set before any store is
        // resolved (the middleware runs early); under Octane the store is
        // re-resolved per request so this continues to hold.
        config(['cache.prefix' => $brand->key . '-cache']);

        if ($brand->sessionCookie !== null) {
            config(['session.cookie' => $brand->sessionCookie]);
        }

        if ($brand->sessionDomain !== null) {
            config(['session.domain' => $brand->sessionDomain]);
        }
    }
}
