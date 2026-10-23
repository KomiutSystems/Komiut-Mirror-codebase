<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves 422 sentences in the language the caller's phone shows.
 *
 * Only `sw` is recognised; anything else, including a browser's long
 * `Accept-Language` list, stays English. Request-scoped only — queued pushes
 * pick their language per device token instead (PlatformNotification::fcmFor),
 * because a worker outlives the request that set this.
 */
class SetLocaleFromHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        if (str_starts_with(strtolower(trim((string) $request->header('Accept-Language'))), 'sw')) {
            App::setLocale('sw');
        }

        return $next($request);
    }
}
