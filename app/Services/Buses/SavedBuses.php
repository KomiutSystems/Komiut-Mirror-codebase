<?php

declare(strict_types=1);

namespace App\Services\Buses;

use App\Models\Queue;
use App\Models\Sacco;
use App\Models\User;
use App\Services\Driver\VehicleAssignment;
use App\Services\Location\VehicleLocationService;
use App\Services\Sql\PlateSql;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Saved buses: what the passenger app may learn about a bus somebody saved on
 * their phone, and nothing else. See docs/school-bus-tracking.md ("Saved
 * buses").
 *
 * The app keeps the list; the server keeps no record of who saved what. It
 * answers two questions: "which bus has this plate" (search) and "where are
 * these buses right now" (live). Both are OPEN to any signed-in user who knows
 * the plate or the id -- a product decision -- school buses included.
 *
 * Every read is by the query builder, so no tenant scope narrows it and none
 * widens it: the walls are rebuilt here, in one place, and nothing else.
 *
 *   - In service: the vehicle is switched on and so is its operator (a SACCO
 *     or a school). A bus that is off does not exist as far as the app is
 *     concerned.
 *   - Brand: a MATATU is seen only from its own brand's app, as on the
 *     passenger nearby map (see brandWall()). A SCHOOL bus is seen from both
 *     apps: a parent's phone runs whichever app they installed.
 *   - Fields: each response is built field by field. Never a till, merchant
 *     code, financier, owner, driver or phone number.
 */
final class SavedBuses
{
    /** Plates are unique; this is a ceiling for search, not a page. */
    public const SEARCH_LIMIT = 5;

    /** The most buses one live read answers for: a person's saved list, not a fleet. */
    public const MAX_IDS = 20;

    /**
     * Buses carrying this plate, as typed. An exact match on the normalised
     * plate -- the comparison driver sign-in uses -- never a contains-match:
     * "KC" must not list every bus with a K and a C in its plate.
     *
     * @return list<array{id:int, plate:string, fleet_no:?string, operator:array{id:int, name:string, kind:string}}>
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
     * Where these buses are right now, in the order asked, plus the ids that
     * are not answered (unknown, switched off, or the other brand's matatu --
     * one list for all of them, so a caller cannot tell which).
     *
     * ONE query whatever the number of ids: the bus and its operator, its
     * position only if live (broadcasting, inside VehicleLocationService::
     * FRESH_SECONDS -- a stale row is never fetched, so there is no path to
     * where a bus WAS), and the open live run that position points at, with
     * its route.
     *
     * @param  list<int>  $ids  distinct, positive
     * @return array{buses: list<array<string, mixed>>, missing: list<int>}
     */
    public function live(array $ids, ?User $viewer, ?string $brand): array
    {
        if ($ids === []) {
            return ['buses' => [], 'missing' => []];
        }

        $since = now()->subSeconds(VehicleLocationService::FRESH_SECONDS);

        // An open run is Pending or Active, as LiveRun reads it, and only ever
        // a `live` queue: a place in a stage's line is not a trip.
        $openRuns = DB::table('queues')
            ->join('queue_statuses', 'queue_statuses.id', '=', 'queues.queue_status_id')
            ->where('queues.kind', Queue::KIND_LIVE)
            ->whereIn('queue_statuses.status', ['Pending', 'Active'])
            ->select(['queues.id', 'queues.vehicle_id', 'queues.route_id']);

        $rows = $this->visible($viewer, $brand)
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

        $buses = [];
        $missing = [];
        foreach ($ids as $id) {
            $row = $rows->get($id);
            if ($row === null) {
                $missing[] = $id;

                continue;
            }

            $buses[] = self::bus($row) + [
                'live' => self::position($row),
                'trip' => $row->recorded_at !== null && $row->run_id !== null ? [
                    'queue_id' => (int) $row->run_id,
                    'route' => $row->route_id === null ? null : ['id' => (int) $row->route_id, 'name' => $row->route_name],
                ] : null,
                'channel' => 'private-bus.'.$id,
            ];
        }

        return ['buses' => $buses, 'missing' => $missing];
    }

    /**
     * Is this a bus in service -- on, in an operator that is on? What the
     * bus.{id} channel admits on (routes/channels.php). No brand: the
     * broadcasting auth request carries none.
     */
    public static function inService(int $vehicleId): bool
    {
        return $vehicleId > 0 && self::inServiceQuery()->where('vehicles.id', $vehicleId)->exists();
    }

    private const BUS_COLUMNS = [
        'vehicles.id', 'vehicles.plate', 'vehicles.fleet_no',
        'saccos.id as operator_id', 'saccos.name as operator_name', 'saccos.kind as operator_kind',
    ];

    /** @return array{id:int, plate:string, fleet_no:?string, operator:array{id:int, name:string, kind:string}} */
    private static function bus(object $row): array
    {
        return [
            'id' => (int) $row->id,
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
     * came from (X-App-Key / host, in Context), unless no brand is active or
     * the caller is bounded by something tighter -- a superadmin (sees
     * everything) or a member of a SACCO (whom SaccoScope then confines to
     * their own SACCO, in both brands). The same here:
     *
     *   - no brand in context, or a superadmin: no wall;
     *   - otherwise a matatu must carry the app's brand, or belong to the
     *     caller's own SACCO (the one set nearby shows a SACCO member across
     *     brands);
     *   - a school bus passes in either app.
     *
     * SaccoScope's narrowing to the caller's own SACCO is not mirrored: saved
     * buses are open by plate within the brand, by design.
     */
    private static function brandWall(QueryBuilder $query, ?User $viewer, ?string $brand): void
    {
        if ($brand === null || $brand === '' || ($viewer !== null && $viewer->isSuperAdmin())) {
            return;
        }

        $ownSacco = $viewer?->currentSaccoId();

        $query->where(function (QueryBuilder $wall) use ($brand, $ownSacco): void {
            $wall->where('saccos.kind', Sacco::KIND_SCHOOL)
                ->orWhere('vehicles.brand', $brand);
            if ($ownSacco !== null) {
                $wall->orWhere('vehicles.sacco_id', $ownSacco);
            }
        });
    }
}
