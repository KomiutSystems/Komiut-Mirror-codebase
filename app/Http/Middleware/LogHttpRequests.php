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
 * Records one row per API request into request_logs so the super-admin console
 * can see live traffic without SSH access.
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
}
