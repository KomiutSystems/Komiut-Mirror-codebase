<?php

declare(strict_types=1);

namespace App\Providers\Super;

use App\Services\Super\Access\AccessChangeRecorder;
use Illuminate\Support\ServiceProvider;

/**
 * Access/privilege domain wiring for the super-admin console.
 *
 * The domain's emitters are invoked directly from the controllers that own the
 * privilege change (AuthController login/reset, RolesController role/permission
 * sync) via App\Services\Super\Access\AccessChangeRecorder — there are no spatie
 * model events to observe, so boot() is intentionally empty. The recorder is
 * autowirable; the singleton binding just shares one instance per request.
 */
class AccessEventsProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AccessChangeRecorder::class);
    }

    public function boot(): void {}
}
