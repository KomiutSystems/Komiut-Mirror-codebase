<?php

declare(strict_types=1);

namespace App\Services\Fares;

use App\Models\RouteFare;
use App\Models\SaccoRoute;
use App\Models\Scopes\SaccoScope;
use Illuminate\Support\Facades\Cache;

/**
 * Single source of truth for "what does this ride cost?".
 *
 * A fare for (sacco, route, from → to) resolves as:
 *   1. an exact stop-pair fare from `route_fares`, else
 *   2. the SACCO's flat `sacco_routes.amount` for the whole route, else
 *   3. null — meaning the SACCO hasn't priced this route; the caller must
 *      refuse rather than trust a client-supplied amount.
 *
 * Every fare for a (sacco, route) is cached as one bundle, so a price preview
 * and a booking are cache hits — the DB is touched once per route per TTL, not
 * once per lookup. The SaccoScope is dropped here so the result is identical
 * whoever asks (a passenger has no sacco; an admin would otherwise be filtered);
 * the brand cache prefix already namespaces entries per brand.
 */
final class FareResolver
{
    public function resolve(int $saccoId, int $routeId, ?int $fromPlaceId, ?int $toPlaceId): ?float
    {
        $bundle = $this->bundle($saccoId, $routeId);

        if ($fromPlaceId !== null && $toPlaceId !== null) {
            $pair = $fromPlaceId . ':' . $toPlaceId;
            if (array_key_exists($pair, $bundle['pairs'])) {
                return $bundle['pairs'][$pair];
            }
        }

        return $bundle['flat'];
    }

    /**
     * @return array{pairs: array<string, float>, flat: float|null}
     */
    public function bundle(int $saccoId, int $routeId): array
    {
        return Cache::remember(
            $this->cacheKey($saccoId, $routeId),
            (int) config('booking.fare_cache_ttl', 3600),
            function () use ($saccoId, $routeId): array {
                $pairs = RouteFare::withoutGlobalScope(SaccoScope::class)
                    ->where('sacco_id', $saccoId)
                    ->where('route_id', $routeId)
                    ->where('status', true)
                    ->get(['from_place_id', 'to_place_id', 'amount'])
                    ->mapWithKeys(fn (RouteFare $f) => [
                        $f->from_place_id . ':' . $f->to_place_id => (float) $f->amount,
                    ])
                    ->all();

                $flat = SaccoRoute::withoutGlobalScope(SaccoScope::class)
                    ->where('sacco_id', $saccoId)
                    ->where('route_id', $routeId)
                    ->where('status', true)
                    ->value('amount');

                return [
                    'pairs' => $pairs,
                    'flat' => $flat !== null ? (float) $flat : null,
                ];
            }
        );
    }

    /** Drop the cached bundle after a SACCO edits a fare. */
    public function forget(int $saccoId, int $routeId): void
    {
        Cache::forget($this->cacheKey($saccoId, $routeId));
    }

    private function cacheKey(int $saccoId, int $routeId): string
    {
        return "fares:v1:{$saccoId}:{$routeId}";
    }
}
