<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Say how long a response is, so nginx can tell a small one from a big one.
 *
 * nginx compresses responses (Docker/prod/nginx.conf) and skips those under
 * gzip_min_length -- but it learns the length ONLY from a Content-Length
 * header, and php-fpm sends none (the body goes out chunked). Without this,
 * every response was compressed for a client that accepts gzip, including the
 * ~50-byte acknowledgements Safaricom, NCBA and Co-op wait for. Those must go
 * out exactly as before, so they carry their length and stay under the bar.
 *
 * The outermost global middleware, so the body is final when it is measured.
 * Streamed and file responses are left alone: their length is not a string's.
 */
class SetContentLength
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return $response;
        }
        if ($response->headers->has('Content-Length') || $response->headers->has('Transfer-Encoding')) {
            return $response;
        }

        $content = $response->getContent();
        if (is_string($content)) {
            $response->headers->set('Content-Length', (string) strlen($content));
        }

        return $response;
    }
}
