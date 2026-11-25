<?php

declare(strict_types=1);

namespace App\Services\Buses;

use App\Brands\BrandRegistry;
use App\Models\Queue;
use App\Models\Sacco;
use App\Models\User;
use App\Services\Driver\VehicleAssignment;
use App\Services\Location\VehicleLocationService;
use App\Services\Sql\PlateSql;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Throwable;

/**
 * Saved buses: what the passenger app may learn about a bus somebody saved on
 * their phone, and nothing else. See docs/school-bus-tracking.md ("Saved
 * buses").
 *
 * The app keeps the list; the server keeps no record of who saved what. It
 * answers two questions: "which bus has this plate" (search) and "where are
 * these buses right now" (live). Both are OPEN to any signed-in user who knows
 * the plate -- a product decision -- school buses included.
 *
 * The plate is the only way in. Search hands back each bus's KEY (key()): its
 * id plus a signature only this server can make. buses/live and the bus
 * channel take keys, never bare ids -- vehicle ids are sequential, and reading
 * by id would let anyone walk 1, 2, 3... and rebuild the whole school-bus
 * fleet without knowing a single plate.
 *
 * Every read is by the query builder, so no tenant scope narrows it and none
 * widens it: the walls are rebuilt here, in one place, and nothing else.
 *
 *   - In service: the vehicle is switched on and so is its operator (a SACCO
 *     or a school). A bus that is off does not exist as far as the app is
 *     concerned.
 *   - Brand: a MATATU is seen only from its own brand's app, as on the
 *     passenger nearby map (see brandWall()). A SCHOOL bus is seen from both
 *     apps: a parent's phone runs whichever app they installed. The same wall
 *     on search, on live and on the bus channel (admitsToChannel()).
 *   - Fields: each response is built field by field. Never a till, merchant
 *     code, financier, owner, driver or phone number.
 */
final class SavedBuses
{
    /** Plates are unique; this is a ceiling for search, not a page. */
    public const SEARCH_LIMIT = 5;

    /** The most buses one live read answers for: a person's saved list, not a fleet. */
    public const MAX_KEYS = 20;

    /** A bus key as key() makes it: the id, a dash, 20 hex characters of signature. */
    public const KEY_PATTERN = '/^([1-9][0-9]{0,17})-([0-9a-f]{20})$/';

    /** Bus channel subscriptions one user may ask for per minute. */
    public const CHANNEL_AUTHS_PER_MINUTE = 60;

    /**
     * How long a bus counts as watched after someone subscribed to its channel,
     * renewed by every ping broadcast to it (isWatched()). Long enough that a
     * bus parked for a while does not leave an open map frozen when it moves
     * again; short enough that a bus nobody opened today costs no broadcasts.
     */
    public const WATCH_SECONDS = 2 * 3600;

    /**
     * A bus's key: what search hands out and live and the channel take.
     *
     * The id plus an HMAC of it under the app key, cut to 80 bits: stable for
     * the life of the bus (the app stores it), not derivable without the
     * server's secret, so the only way to one is buses/search with the plate.
     * Rotating APP_KEY invalidates every saved key; the app then finds the
     * bus by plate again (its key comes back under `missing`).
     */
    public static function key(int $vehicleId): string
    {
        $secret = (string) config('app.key');
        if ($secret === '') {
            throw new RuntimeException('Bus keys need APP_KEY.');
        }

        return $vehicleId.'-'.substr(hash_hmac('sha256', 'saved-bus:'.$vehicleId, $secret), 0, 20);
    }

    /** The bus a key names, or null when it is not a key this server made. */
    public static function idFromKey(string $key): ?int
    {
        if (preg_match(self::KEY_PATTERN, $key, $m) !== 1) {
            return null;
        }
        $id = (int) $m[1];

        return hash_equals(self::key($id), $key) ? $id : null;
    }

    /**
     * Buses carrying this plate, as typed. An exact match on the normalised
     * plate -- the comparison driver sign-in uses -- never a contains-match:
     * "KC" must not list every bus with a K and a C in its plate.
     *
     * @return list<array{id:int, key:string, plate:string, fleet_no:?string, operator:array{id:int, name:string, kind:string}}>
     */
    public function search(string $typed, ?User $viewer, ?string $brand): array
    {
        $normalised = VehicleAssignment::normalisePlate($typed);

        // "---" normalises to "" and `= ''` would match the legacy junk rows
        // with empty plates; see VehicleAssignment::matching().
        if ($normalised === '') {
            return [];
        }

        return $this->visible($viewer, $brand)
            ->whereRaw(PlateSql::normaliseColumn('vehicles.plate').' = ?', [$normalised])
            ->orderBy('vehicles.id')
            ->limit(self::SEARCH_LIMIT)
            ->get(self::BUS_COLUMNS)
            ->map(fn (object $row) => self::bus($row))
            ->all();
    }

    /**
     * Where these buses are right now, in the order asked, plus the keys that
     * are not answered (forged, unknown, switched off, or the other brand's
     * matatu -- one list for all of them, so a caller cannot tell which).
     *
     * ONE query whatever the number of keys: the bus and its operator, its
     * position only if live (broadcasting, inside VehicleLocationService::
     * FRESH_SECONDS -- a stale row is never fetched, so there is no path to
     * where a bus WAS), and the open live run that position points at, with
     * its route.
     *
     * @param  list<string>  $keys  distinct, shaped like KEY_PATTERN
     * @return array{buses: list<array<string, mixed>>, missing: list<string>}
     */
    public function live(array $keys, ?User $viewer, ?string $brand): array
    {
        $ids = [];
        foreach ($keys as $key) {
            $id = self::idFromKey($key);
            if ($id !== null) {
                $ids[$key] = $id;
            }
        }

        $rows = $ids === [] ? collect() : $this->liveRows(array_values(array_unique($ids)), $viewer, $brand);

        $buses = [];
        $missing = [];
        foreach ($keys as $key) {
            $row = isset($ids[$key]) ? $rows->get($ids[$key]) : null;
            if ($row === null) {
                $missing[] = $key;

                continue;
            }

            $buses[] = self::bus($row) + [
                'live' => self::position($row),
                'trip' => $row->recorded_at !== null && $row->run_id !== null ? [
                    'queue_id' => (int) $row->run_id,
                    'route' => $row->route_id === null ? null : ['id' => (int) $row->route_id, 'name' => $row->route_name],
                ] : null,
                'channel' => 'private-bus.'.$key,
            ];
        }

        return ['buses' => $buses, 'missing' => $missing];
    }

    /**
     * May this user listen on bus.{key}? The same answer buses/live gives for
     * that key -- in service, behind the same brand wall -- so the channel is
     * never a way round `missing`. Capped per user per minute, since
     * /broadcasting/auth carries no throttle of its own.
     *
     * Admitting marks the bus as watched (isWatched()).
     */
    public function admitsToChannel(User $viewer, string $key, ?string $brand): bool
    {
        $id = self::idFromKey($key);
        if ($id === null) {
            return false;
        }

        $limiterKey = 'bus-channel:'.$viewer->getAuthIdentifier();
        if (RateLimiter::tooManyAttempts($limiterKey, self::CHANNEL_AUTHS_PER_MINUTE)) {
            return false;
        }
        RateLimiter::hit($limiterKey, 60);

        if (! $this->visible($viewer, $brand)->where('vehicles.id', $id)->exists()) {
            return false;
        }

        self::markWatched($id);

        return true;
    }

    /**
     * The brand a /broadcasting/auth request comes from. That route runs
     * without the `brand` middleware (other channels must not 404 for a
     * client that sends no X-App-Key), so it is resolved here the way
     * ResolveBrand does: the app's X-App-Key, else the host. Null when
     * neither resolves -- and brandWall() then admits no matatu at all.
     */
    public static function brandOf(Request $request): ?string
    {
        $registry = app(BrandRegistry::class);
        $brand = $registry->resolveByAppKey($request->header('X-App-Key'))
            ?? $registry->resolveByHost($request->getHost());

        return $brand?->key;
    }

    /**
     * Is anyone watching this bus -- did someone subscribe to its channel in
     * the last WATCH_SECONDS? Asked by VehicleMoved for every fresh ping, so a
     * bus nobody has open costs no broadcast; a yes renews the window, so an
     * open map does not freeze while its bus keeps moving.
     *
     * One round trip (EXPIRE answers "was it there" and renews it). When the
     * shared store cannot answer -- no shared store configured, Redis down --
     * the answer is yes: a broadcast nobody hears costs less than a dot that
     * stops moving.
     */
    public static function isWatched(int $vehicleId): bool
    {
        $store = self::watchStore();
        if ($store === null) {
            return true;
        }

        try {
            return $store->touch(self::watchKey($vehicleId), self::WATCH_SECONDS);
        } catch (Throwable $e) {
            report($e);

            return true;
        }
    }

    public static function markWatched(int $vehicleId): void
    {
        try {
            self::watchStore()?->put(self::watchKey($vehicleId), true, self::WATCH_SECONDS);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private static function watchKey(int $vehicleId): string
    {
        return 'bus-watched:'.$vehicleId;
    }

    /**
     * Where "watched" lives: a store every brand and every host shares. The
     * default store will not do: BrandContext re-prefixes it per brand, and a
     * school bus is watched from one brand's app while its driver pings
     * through the other's. In production that is the `shared` Redis store; in
     * the test suite the in-process array store; anywhere else there is no
     * shared store, and every bus counts as watched.
     */
    private static function watchStore(): ?CacheRepository
    {
        return match (config('cache.default')) {
            'redis' => Cache::store('shared'),
            'array' => Cache::store('array'),
            default => null,
        };
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, object>
     */
    private function liveRows(array $ids, ?User $viewer, ?string $brand)
    {
        $since = now()->subSeconds(VehicleLocationService::FRESH_SECONDS);

        // An open run is Pending or Active, as LiveRun reads it, and only ever
        // a `live` queue: a place in a stage's line is not a trip.
        $openRuns = DB::table('queues')
            ->join('queue_statuses', 'queue_statuses.id', '=', 'queues.queue_status_id')
            ->where('queues.kind', Queue::KIND_LIVE)
            ->whereIn('queue_statuses.status', ['Pending', 'Active'])
            ->select(['queues.id', 'queues.vehicle_id', 'queues.route_id']);

        return $this->visible($viewer, $brand)
            ->whereIn('vehicles.id', $ids)
            ->leftJoin('vehicle_locations as live_loc', function (JoinClause $join) use ($since): void {
                $join->on('live_loc.vehicle_id', '=', 'vehicles.id')
                    ->where('live_loc.broadcasting', '=', true)
                    ->where('live_loc.recorded_at', '>=', $since);
            })
            // The run must be THIS bus's: a location row's queue_id is the
            // driver's ping, and one bus's trip is never shown on another.
            ->leftJoinSub($openRuns, 'live_run', function (JoinClause $join): void {
                $join->on('live_run.id', '=', 'live_loc.queue_id')
                    ->on('live_run.vehicle_id', '=', 'vehicles.id');
            })
            ->leftJoin('routes as live_route', 'live_route.id', '=', 'live_run.route_id')
            ->get(array_merge(self::BUS_COLUMNS, [
                'live_loc.latitude', 'live_loc.longitude', 'live_loc.heading', 'live_loc.speed_kmh', 'live_loc.recorded_at',
                'live_run.id as run_id', 'live_route.id as route_id', 'live_route.name as route_name',
            ]))
            ->keyBy(fn (object $row) => (int) $row->id);
    }

    private const BUS_COLUMNS = [
        'vehicles.id', 'vehicles.plate', 'vehicles.fleet_no',
        'saccos.id as operator_id', 'saccos.name as operator_name', 'saccos.kind as operator_kind',
    ];

    /** @return array{id:int, key:string, plate:string, fleet_no:?string, operator:array{id:int, name:string, kind:string}} */
    private static function bus(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'key' => self::key((int) $row->id),
            'plate' => (string) $row->plate,
            'fleet_no' => $row->fleet_no === null ? null : (string) $row->fleet_no,
            'operator' => [
                'id' => (int) $row->operator_id,
                'name' => (string) $row->operator_name,
                'kind' => $row->operator_kind === Sacco::KIND_SCHOOL ? Sacco::KIND_SCHOOL : Sacco::KIND_SACCO,
            ],
        ];
    }

    /** @return array{latitude:float, longitude:float, heading:int, speed_kmh:?float, recorded_at:string, age_seconds:int}|null */
    private static function position(object $row): ?array
    {
        if ($row->recorded_at === null) {
            return null;
        }

        $recordedAt = Carbon::parse($row->recorded_at);

        return [
            'latitude' => (float) $row->latitude,
            'longitude' => (float) $row->longitude,
            'heading' => (int) $row->heading,
            'speed_kmh' => $row->speed_kmh === null ? null : (float) $row->speed_kmh,
            'recorded_at' => $recordedAt->toIso8601String(),
            'age_seconds' => max(0, (int) floor($recordedAt->diffInSeconds(now(), false))),
        ];
    }

    /** Buses in service, behind the brand wall: the only door either endpoint has to a bus. */
    private function visible(?User $viewer, ?string $brand): QueryBuilder
    {
        $query = self::inServiceQuery();
        self::brandWall($query, $viewer, $brand);

        return $query;
    }

    private static function inServiceQuery(): QueryBuilder
    {
        return DB::table('vehicles')
            ->join('saccos', 'saccos.id', '=', 'vehicles.sacco_id')
            ->where('vehicles.status', true)
            ->where('saccos.status', 1);
    }

    /**
     * The passenger nearby map's brand wall, for matatus.
     *
     * Nearby reads VehicleLocation through its global scopes: BrandScope keeps
     * a vehicle only when vehicles.brand is the brand of the app the request
     * came from (X-App-Key / host), unless the caller is bounded by something
     * tighter (BrandScope::boundedBySomethingTighter) -- a superadmin (sees
     * everything), a bank (whom FinancierScope then confines to the buses it
     * financed, in both brands) or a member of a SACCO (whom SaccoScope then
     * confines to their own SACCO, in both brands). The same here:
     *
     *   - a superadmin: no wall;
     *   - otherwise a matatu must carry the app's brand, or be one nearby
     *     shows this caller across brands: in their own SACCO, or financed by
     *     their bank;
     *   - a school bus passes in either app.
     *
     * No brand at all (a channel auth that names no app) admits no matatu
     * beyond those exceptions: the wall fails closed.
     *
     * Saved buses are open by plate within the brand, by design, so SaccoScope's
     * and FinancierScope's narrowing is not mirrored -- only their exemption
     * from the brand.
     */
    private static function brandWall(QueryBuilder $query, ?User $viewer, ?string $brand): void
    {
        if ($viewer !== null && $viewer->isSuperAdmin()) {
            return;
        }

        $ownSacco = $viewer?->currentSaccoId();
        $bank = $viewer !== null && $viewer->isBankUser() ? $viewer->currentFinancier() : null;

        $query->where(function (QueryBuilder $wall) use ($brand, $ownSacco, $bank): void {
            $wall->where('saccos.kind', Sacco::KIND_SCHOOL);
            if ($brand !== null && $brand !== '') {
                $wall->orWhere('vehicles.brand', $brand);
            }
            if ($ownSacco !== null) {
                $wall->orWhere('vehicles.sacco_id', $ownSacco);
            }
            if ($bank !== null) {
                $wall->orWhere('vehicles.financier', $bank->value);
            }
        });
    }
}
