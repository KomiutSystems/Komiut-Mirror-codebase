<?php

declare(strict_types=1);

namespace App\Services\Fares;

use App\Models\FarePeriod;
use App\Models\RouteFare;
use App\Models\SaccoRoute;
use App\Models\Scopes\SaccoScope;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Single source of truth for "what does this ride cost?".
 *
 * A fare for (sacco, route, from → to) resolves as:
 *   1. a stop-pair fare attached to a PEAK PERIOD that covers this moment,
 *      highest-priority period first, else
 *   2. the base stop-pair fare from `route_fares`, else
 *   3. the SACCO's flat `sacco_routes.amount` for the whole route, else
 *   4. null — meaning the SACCO hasn't priced this route; the caller must
 *      refuse rather than trust a client-supplied amount.
 *
 * WHOLE-ROUTE PEAK PRICING works through tier 1, not tier 3: a route's own
 * endpoints are a stop pair like any other, and both booking flows already
 * substitute (route.from_id, route.to_id) when the passenger names no stops. So
 * a SACCO that wants "the whole run costs more at 7am" prices the endpoint pair
 * against a period. sacco_routes.amount stays what it is — the untimed floor.
 *
 * CACHING AND TIME. Every fare and every period for a (sacco, route) is cached
 * as one bundle, so a price preview and a booking are cache hits. The bundle is
 * deliberately TIME-INDEPENDENT: it holds the period DEFINITIONS, and which
 * period is live is decided per request, in PHP, against Kenyan wall-clock.
 * Baking "the active price" into the cache instead would freeze whatever was
 * true when the first request of the hour landed, and a 06:00 peak would start
 * somewhere between 06:00 and 07:00 depending on traffic — the single most
 * likely way to get this wrong.
 *
 * The SaccoScope is dropped here so the result is identical whoever asks (a
 * passenger has no sacco; an admin would otherwise be filtered); the brand cache
 * prefix already namespaces entries per brand. Tenancy is enforced by the
 * explicit $saccoId argument instead.
 */
final class FareResolver
{
    /**
     * @param  CarbonInterface|null  $at  The moment to price at. Defaults to now.
     *                                    Passing it explicitly is what makes peak
     *                                    pricing testable without freezing clocks.
     */
    public function resolve(
        int $saccoId,
        int $routeId,
        ?int $fromPlaceId,
        ?int $toPlaceId,
        ?CarbonInterface $at = null
    ): ?float {
        $bundle = $this->bundle($saccoId, $routeId);
        $moment = $at ?? Carbon::now();

        if ($fromPlaceId !== null && $toPlaceId !== null) {
            $pair = $fromPlaceId . ':' . $toPlaceId;

            // Periods are pre-sorted highest priority first, so the first one
            // that both covers this moment AND prices this pair wins. A period
            // that is live but has no price for this segment falls through to
            // the next one rather than blocking the base fare.
            foreach ($this->activePeriods($bundle, $moment) as $periodId) {
                if (isset($bundle['periodPairs'][$periodId][$pair])) {
                    return $bundle['periodPairs'][$periodId][$pair];
                }
            }

            if (array_key_exists($pair, $bundle['pairs'])) {
                return $bundle['pairs'][$pair];
            }
        }

        return $bundle['flat'];
    }

    /**
     * Which periods cover this moment, highest priority first.
     *
     * @param  array<string, mixed>  $bundle
     * @return array<int, int> period ids
     */
    public function activePeriods(array $bundle, CarbonInterface $moment): array
    {
        $live = [];

        foreach ($bundle['periods'] as $row) {
            // Rehydrated rather than stored as models: the bundle goes through
            // the cache serialiser, and covers() is the only behaviour needed.
            $period = new FarePeriod();
            $period->forceFill($row);

            if ($period->covers($moment)) {
                $live[] = (int) $row['id'];
            }
        }

        return $live;
    }

    /**
     * The named period a price came from, for showing a passenger WHY they were
     * quoted what they were quoted. Null outside every window.
     *
     * @return array{id: int, name: string}|null
     */
    public function activePeriodFor(
        int $saccoId,
        int $routeId,
        ?int $fromPlaceId,
        ?int $toPlaceId,
        ?CarbonInterface $at = null
    ): ?array {
        if ($fromPlaceId === null || $toPlaceId === null) {
            return null;
        }

        $bundle = $this->bundle($saccoId, $routeId);
        $pair = $fromPlaceId . ':' . $toPlaceId;

        foreach ($this->activePeriods($bundle, $at ?? Carbon::now()) as $periodId) {
            if (isset($bundle['periodPairs'][$periodId][$pair])) {
                return [
                    'id' => $periodId,
                    'name' => (string) ($bundle['periodNames'][$periodId] ?? ''),
                ];
            }
        }

        return null;
    }

    /**
     * @return array{
     *     pairs: array<string, float>,
     *     periodPairs: array<int, array<string, float>>,
     *     periods: array<int, array<string, mixed>>,
     *     periodNames: array<int, string>,
     *     flat: float|null
     * }
     */
    public function bundle(int $saccoId, int $routeId): array
    {
        return Cache::remember(
            $this->cacheKey($saccoId, $routeId),
            (int) config('booking.fare_cache_ttl', 3600),
            function () use ($saccoId, $routeId): array {
                $fares = RouteFare::withoutGlobalScope(SaccoScope::class)
                    ->where('sacco_id', $saccoId)
                    ->where('route_id', $routeId)
                    ->where('status', true)
                    ->get(['from_place_id', 'to_place_id', 'amount', 'fare_period_id']);

                $pairs = [];
                $periodPairs = [];

                foreach ($fares as $fare) {
                    $pair = $fare->from_place_id . ':' . $fare->to_place_id;

                    if ($fare->fare_period_id === null) {
                        $pairs[$pair] = (float) $fare->amount;

                        continue;
                    }

                    $periodPairs[(int) $fare->fare_period_id][$pair] = (float) $fare->amount;
                }

                // Sorted ONCE, here, so resolve() can stop at its first match.
                // id is the tiebreak after priority: two periods at the same
                // priority must not price differently depending on which row the
                // planner happened to return first.
                $periods = FarePeriod::withoutGlobalScope(SaccoScope::class)
                    ->where('sacco_id', $saccoId)
                    ->where('status', true)
                    ->orderByDesc('priority')
                    ->orderBy('id')
                    ->get(['id', 'name', 'days', 'start_time', 'end_time', 'priority', 'status']);

                $flat = SaccoRoute::withoutGlobalScope(SaccoScope::class)
                    ->where('sacco_id', $saccoId)
                    ->where('route_id', $routeId)
                    ->where('status', true)
                    ->value('amount');

                return [
                    'pairs' => $pairs,
                    'periodPairs' => $periodPairs,
                    'periods' => $periods->map(fn (FarePeriod $p) => [
                        'id' => (int) $p->id,
                        'name' => (string) $p->name,
                        'days' => (array) $p->days,
                        'start_time' => $p->getRawOriginal('start_time'),
                        'end_time' => $p->getRawOriginal('end_time'),
                        'priority' => (int) $p->priority,
                        'status' => true,
                    ])->all(),
                    'periodNames' => $periods->mapWithKeys(
                        fn (FarePeriod $p) => [(int) $p->id => (string) $p->name]
                    )->all(),
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

    /**
     * Drop every cached bundle for a SACCO.
     *
     * Editing a PERIOD changes the price of every route that prices against it,
     * and the bundles are keyed per route, so there is no single key to forget.
     * Route ids come from the SACCO's own sacco_routes — a SACCO has tens of
     * routes, not thousands, and a period edit is a rare deliberate act.
     */
    public function forgetSacco(int $saccoId): void
    {
        SaccoRoute::withoutGlobalScope(SaccoScope::class)
            ->where('sacco_id', $saccoId)
            ->pluck('route_id')
            ->each(fn ($routeId) => $this->forget($saccoId, (int) $routeId));
    }

    /**
     * v2: the bundle gained periods. The version is part of the key so a deploy
     * cannot serve a v1 bundle — which has no 'periods' index — to v2 code that
     * dereferences it.
     */
    private function cacheKey(int $saccoId, int $routeId): string
    {
        return "fares:v2:{$saccoId}:{$routeId}";
    }
}
