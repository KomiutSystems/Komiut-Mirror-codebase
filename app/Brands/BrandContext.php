<?php

declare(strict_types=1);

namespace App\Brands;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Context;

/**
 * Activates a resolved Brand for the current lifecycle.
 *
 * There is ONE shared database. A brand is a branding + feature-flag layer, not
 * a database boundary: passengers are global across brands, while a brand's
 * SACCOs and their fleet/operations are partitioned by the `brand` column and
 * the BrandScope. So activating a brand does NOT switch connections — it records
 * the brand so the scope and the app config can key off it.
 */
final readonly class BrandContext
{
    public function __construct(private Application $app) {}

    public function apply(Brand $brand): void
    {
        // Overrides the scoped Brand binding for this lifecycle.
        $this->app->instance(Brand::class, $brand);

        // Carries the brand across the HTTP -> queued-job boundary and is what
        // BrandScope reads to constrain brand-owned models.
        Context::add('brand', $brand->key);

        // Namespace the shared Redis keyspace per brand.
        config(['cache.prefix' => $brand->key . '-cache']);

        if ($brand->sessionCookie !== null) {
            config(['session.cookie' => $brand->sessionCookie]);
        }

        if ($brand->sessionDomain !== null) {
            config(['session.domain' => $brand->sessionDomain]);
        }
    }
}
