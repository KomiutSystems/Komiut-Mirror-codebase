<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stamps `users.last_active_at` for the authenticated caller.
 *
 * Two deliberate choices keep this off the hot path:
 *
 *  1. The write happens in terminate(), i.e. AFTER the response has been sent,
 *     so it adds no latency to the request.
 *  2. It only writes when the stored value is older than STALE_AFTER_MINUTES.
 *     Without that, a polling mobile client would issue a row update every few
 *     seconds per driver.
 *
 * It uses the query builder rather than Eloquent on purpose: that bypasses
 * `updated_at`, model events and global scopes. `updated_at` must keep meaning
 * "the record was edited" — if activity bumped it, we would lose the ability to
 * tell an edited account from a merely-online one.
 */
class TouchLastActive
{
    private const STALE_AFTER_MINUTES = 5;

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->stamp($request->user());
    }

    private function stamp(mixed $user): void
    {
        if (! $user instanceof User) {
            return;
        }

        if (! $this->isStale($user)) {
            return;
        }

        DB::table('users')
            ->where('id', $user->getKey())
            ->update(['last_active_at' => now()]);
    }

    private function isStale(User $user): bool
    {
        $last = $user->last_active_at;

        return $last === null || $last->lt(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }
}
