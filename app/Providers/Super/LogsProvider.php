<?php

declare(strict_types=1);

namespace App\Providers\Super;

use App\Http\Middleware\LogHttpRequests;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the super-admin LOGS domain.
 *
 * Registers LogHttpRequests as a GLOBAL middleware from boot() (rather than
 * editing app/Http/Kernel.php, which another agent owns) so every API request is
 * recorded into request_logs. The middleware is terminable and its write is
 * Throwable-guarded, so this is safe to run on every request in every context.
 */
class LogsProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->app->make(Kernel::class)->pushMiddleware(LogHttpRequests::class);
    }
}
