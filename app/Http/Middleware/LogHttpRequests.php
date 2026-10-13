<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records API requests into request_logs so the super-admin console can see
 * live traffic without SSH access -- the ones worth reading, see worthKeeping().
 *
 * Two deliberate choices keep it off the hot path (mirroring TouchLastActive):
 *
 *  1. The write happens in terminate(), i.e. AFTER the response has been sent, so
 *     it adds no latency and measures the full request duration.
 *  2. It uses the query builder, not Eloquent — no model events, no global
 *     scopes, no updated_at.
 *
 * We record method/path/status/duration and who made the call, never the request
 * body: bodies can carry passwords, tokens or PII. The whole write is wrapped in
 * a Throwable swallow — a logging failure (e.g. an unmigrated box) must never
 * turn a served response into a 500, and this middleware runs globally.
 */
class LogHttpRequests
{
    /**
     * POSTs that are not someone changing something, or are called so often
     * that recording every hit is pure cost: Safaricom and bank confirmations
     * (their full bodies are kept in mpesa_logs anyway), the driver's location
     * ping (every few seconds per live bus) and the app's profile fetch, which
     * is a POST. Sampled like any routine request.
     */
    private const HOT_PATHS = [
        '#(^|/)confirmation(_new)?(/|$)#',
        '#(^|/)coop/(payments|mpesa)$#',
        '#book_a_ride/location#',
        '#(^|/)auth/user$#',
    ];

    /** Set in handle(), read in terminate() — REQUEST_TIME_FLOAT/LARAVEL_START are unreliable under PHPUnit. */
    private float $startedAt = 0.0;

    public function handle(Request $request, Closure $next): Response
    {
        $this->startedAt = microtime(true);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $duration = $this->startedAt > 0.0
                ? (int) round((microtime(true) - $this->startedAt) * 1000)
                : null;

            $user = $request->user();

            if (! $this->worthKeeping($request, $response, $duration, $user instanceof User)) {
                return;
            }

            DB::table('request_logs')->insert([
                'method' => substr($request->getMethod(), 0, 10),
                'path' => substr('/'.ltrim($request->path(), '/'), 0, 255),
                'status' => $response->getStatusCode(),
                'duration_ms' => $duration,
                'user_id' => $user instanceof User ? $user->getKey() : null,
                'brand' => Context::get('brand'),
                'ip' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 512),
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // Never let request logging break a request that has already been served.
        }
    }

    /**
     * WHAT IS WORTH A ROW. This wrote one row for every request -- 115,000 a
     * day, 30% of them the load balancer's own health check and 36% M-Pesa
     * confirmations already kept whole in mpesa_logs -- and the insert was one
     * of the database's ten heaviest statements. The console feed is read to
     * find what went wrong or what someone did, so:
     *
     *   never   the health check (`up`);
     *   always  a server error, a slow request (>= 1s), an error a signed-in
     *           user hit, and anything a signed-in user changed -- except the
     *           HOT_PATHS above;
     *   sampled everything else, at logging.request_log_sample (default 5%).
     *
     * On 2026-10-12 traffic: about 4,700 rows a day instead of 115,000.
     */
    private function worthKeeping(Request $request, Response $response, ?int $durationMs, bool $signedIn): bool
    {
        $path = trim($request->path(), '/');
        if ($path === 'up' || str_ends_with($path, '/up')) {
            return false;
        }

        $status = $response->getStatusCode();
        if ($status >= 500 || ($durationMs !== null && $durationMs >= 1000)) {
            return true;
        }

        $hot = false;
        foreach (self::HOT_PATHS as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                $hot = true;
                break;
            }
        }

        if (! $hot && $signedIn && ($status >= 400 || ! in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true))) {
            return true;
        }

        $rate = (float) config('logging.request_log_sample', 0.05);

        return $rate >= 1.0 || ($rate > 0.0 && mt_rand() / mt_getrandmax() < $rate);
    }
}
