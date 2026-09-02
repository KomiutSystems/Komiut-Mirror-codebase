<?php

namespace App\Providers;

use App\Support\Http\MoneyIngestion;
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
            // Money ingestion is NEVER rate limited. The whole fleet's C2B
            // confirmations arrive from one forwarding host, so a per-IP cap
            // counts 800 matatus as a single caller and starts refusing real
            // fares at morning peak — 879 of them on 2026-08-31, with no trace
            // left behind, because ThrottleRequests answers before the handler
            // that writes the raw-body log. See MoneyIngestion for why the
            // answer is "no limit" rather than "a bigger limit".
            if (MoneyIngestion::matches($request)) {
                return Limit::none();
            }

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
