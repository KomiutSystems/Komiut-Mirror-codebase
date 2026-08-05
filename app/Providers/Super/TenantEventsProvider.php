<?php

declare(strict_types=1);

namespace App\Providers\Super;

use App\Models\Sacco;
use App\Observers\SaccoObserver;
use Illuminate\Support\ServiceProvider;

/**
 * Tenant-lifecycle + platform-health console wiring.
 *
 * The SACCO lifecycle (pending review, duplicate suspicion, claim, status change)
 * is caught by observing the model, so every write path is covered without editing
 * a controller. The scheduled detectors (dormancy, daily digest, queue backlog,
 * DB health, TLS expiry) are registered in App\Console\Kernel.
 */
class TenantEventsProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Sacco::observe(SaccoObserver::class);
    }
}
