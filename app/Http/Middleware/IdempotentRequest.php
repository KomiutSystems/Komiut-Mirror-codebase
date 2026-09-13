<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * A write the client may safely retry.
 *
 * A phone on a matatu route loses responses, not requests: the booking was
 * created, the expense was recorded, and the app never heard. Its HTTP layer
 * then retries the POST -- and the second one creates a second booking or
 * records the fuel twice. The client cannot know which happened; only the
 * server can, and only if the client tells it "this is the same attempt".
 *
 * So: send `Idempotency-Key: <any unique string per attempt>` (a UUID minted
 * when the user taps, reused on every retry of that tap). The first request
 * to arrive with a key runs; its status and body are kept for 24 hours; every
 * later request with the same key gets that stored response back, byte for
 * byte, with `Idempotent-Replayed: true`, and writes nothing. Two duplicates
 * in flight at once are serialised on a lock, so the second waits for the
 * first and replays it rather than racing it.
 *
 * Keys are scoped to the authenticated user AND the path: one passenger's key
 * can never replay another's response, and the same key on two endpoints is
 * two attempts. 5xx and 429 are not stored -- those are the server's fault
 * and a retry should genuinely run again. Everything below 500 is stored,
 * including a 422: the answer to "this exact attempt" does not change.
 *
 * Without the header nothing here happens; the endpoint behaves as before.
 * Redis in production (shared across both app hosts), so a retry landing on
 * the other host still sees the first attempt.
 */
class IdempotentRequest
{
    private const TTL_SECONDS = 24 * 3600;

    private const LOCK_SECONDS = 30;

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));

        if ($key === '' || ! $request->isMethod('POST')) {
            return $next($request);
        }

        if (strlen($key) > 128) {
            return response()->json(['error' => 'Idempotency-Key must be 128 characters or fewer.'], 400);
        }

        $cacheKey = sprintf(
            'idempotency:%s:%s:%s',
            (string) ($request->user()?->getAuthIdentifier() ?? 'anon'),
            $request->path(),
            hash('sha256', $key),
        );

        if (($stored = Cache::get($cacheKey)) !== null) {
            return $this->replay($stored);
        }

        $lock = Cache::lock($cacheKey.':lock', self::LOCK_SECONDS);

        if (! $lock->get()) {
            // The same attempt is running on another request right now. Wait
            // for it, then hand back what it produced.
            try {
                $lock->block(self::LOCK_SECONDS - 5);
            } catch (LockTimeoutException) {
                return response()->json(['error' => 'This request is still being processed. Try again in a moment.'], 409);
            }

            try {
                if (($stored = Cache::get($cacheKey)) !== null) {
                    return $this->replay($stored);
                }
            } finally {
                $lock->release();
            }

            // The first run ended in a status we do not store (5xx): fall
            // through and run this one.
            return $this->run($request, $next, $cacheKey, Cache::lock($cacheKey.':lock', self::LOCK_SECONDS));
        }

        return $this->run($request, $next, $cacheKey, $lock);
    }

    /** @param  \Illuminate\Contracts\Cache\Lock  $lock */
    private function run(Request $request, Closure $next, string $cacheKey, $lock): Response
    {
        if (! $lock->get()) {
            return $next($request);
        }

        try {
            $response = $next($request);

            $status = $response->getStatusCode();
            if ($status < 500 && $status !== 429) {
                Cache::put($cacheKey, [
                    'status' => $status,
                    'body' => $response->getContent(),
                    'content_type' => $response->headers->get('Content-Type', 'application/json'),
                ], self::TTL_SECONDS);
            }

            $response->headers->set('Idempotent-Replayed', 'false');

            return $response;
        } finally {
            $lock->release();
        }
    }

    /** @param  array{status: int, body: string, content_type: string}  $stored */
    private function replay(array $stored): Response
    {
        return response($stored['body'], $stored['status'])->withHeaders([
            'Content-Type' => $stored['content_type'],
            'Idempotent-Replayed' => 'true',
        ]);
    }
}
