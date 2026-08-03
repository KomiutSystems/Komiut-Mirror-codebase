<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Login brute-force guard keyed on the ACCOUNT (identifier + IP), not the
        // bare IP — a NAT'd SACCO office shares one IP, so a per-IP cap would lock
        // the whole office out at morning sign-in. The 60/min per-IP `api` limiter
        // above is the DoS backstop; this only bounds tries against one account.
        RateLimiter::for('login', function (Request $request) {
            $identifier = strtolower(trim((string) ($request->input('email') ?: $request->input('phone') ?: '')));

            return Limit::perMinute(8)->by($identifier.'|'.$request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
