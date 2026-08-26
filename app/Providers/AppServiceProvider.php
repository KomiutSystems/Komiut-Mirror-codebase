<?php

namespace App\Providers;

use App\Brands\Brand;
use App\Brands\BrandContext;
use App\Brands\BrandRegistry;
use App\Brands\Exceptions\BrandNotResolved;
use App\Services\Super\Money\LegacyPaymentSource;
use App\Services\Super\Money\MysqlLegacyPaymentSource;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The brand catalogue is immutable and config-derived, so a singleton is
        // correct here — it holds no request state.
        $this->app->singleton(BrandRegistry::class, fn () => new BrandRegistry(config('brands', [])));

        // The CURRENT brand, by contrast, is per-lifecycle. It MUST be scoped(),
        // never singleton(): a singleton would leak brand A into the next queue
        // job / Octane request for brand B and write to the wrong database.
        // Resolving it before a brand is activated is a bug, so fail loudly.
        $this->app->scoped(Brand::class, fn () => throw BrandNotResolved::outsideLifecycle());

        // The legacy side of payments:reconcile-legacy. Bound to the contract so
        // the command never names MySQL, and so the suite — which runs on
        // PostgreSQL with no MySQL anywhere (see phpunit.xml) — can substitute an
        // in-memory source and still exercise the comparison, which is the part
        // that can actually be wrong.
        $this->app->bind(LegacyPaymentSource::class, MysqlLegacyPaymentSource::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {

        Paginator::useBootstrap();
        if (config('app.env') === 'production') {
            \URL::forceScheme('https');
        }

        // The existing API returns bare payloads (e.g. {"vehicles":[...]}), not a
        // "data"-wrapped envelope. Disable Resource wrapping so wiring Resources
        // into controllers preserves the current response shape the apps expect.
        \Illuminate\Http\Resources\Json\JsonResource::withoutWrapping();

        $this->propagateBrandToQueuedJobs();
    }

    /**
     * A queued job runs in a worker with no HTTP request, so nothing activates a
     * brand for it. Context carries the brand key across the dispatch boundary
     * automatically; this hook re-activates the brand (binding + DB connection)
     * as the job's Context is hydrated, before the job body runs.
     */
    private function propagateBrandToQueuedJobs(): void
    {
        Context::hydrated(function ($context): void {
            if (! $context->has('brand')) {
                return;
            }

            $brand = $this->app->make(BrandRegistry::class)->get($context->get('brand'));

            if ($brand !== null) {
                $this->app->make(BrandContext::class)->apply($brand);
            }
        });
    }
}
