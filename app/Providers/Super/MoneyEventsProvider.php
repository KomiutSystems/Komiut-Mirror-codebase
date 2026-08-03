<?php

declare(strict_types=1);

namespace App\Providers\Super;

use Illuminate\Support\ServiceProvider;

/**
 * STUB — super-admin console domain wiring. An agent fills register()/boot() with
 * this domain's event listeners / model observers / middleware. Pre-registered in
 * config/app.php so the app boots during the parallel build.
 */
class MoneyEventsProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void {}
}
