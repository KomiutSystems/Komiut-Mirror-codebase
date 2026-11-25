<?php

declare(strict_types=1);

namespace App\Services\School\Parent;

use App\Models\Place;
use App\Models\Queue;
use App\Models\Sacco;
use App\Models\Vehicle;
use App\Models\VehicleFollower;
use App\Models\VehicleLocation;
use App\Services\Driver\VehicleAssignment;
use App\Services\Location\VehicleLocationService;
use App\Services\Sql\PlateSql;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What a parent may know about the school buses they follow, and nothing else.
 *
 * Every read here is CROSS-TENANT and CROSS-BRAND on purpose: a parent's
 * account belongs to whichever app they installed, the school belongs to
 * neither, and the bus's brand is the school's business. So the tenant scopes
 * are stepped around (withoutGlobalScopes / the query builder) and the wall
 * is rebuilt by hand instead, in two places that never move:
 *
 *   - a bus is only ever reached through confineToActiveSchools() -- a vehicle that
 *     is on, in a school that is on. A matatu, or a bus whose school was
 *     switched off, does not exist as far as a parent is concerned.
 *   - a position is only ever reached through a vehicle_followers row keyed by
 *     the caller's own user id. No code path here takes a vehicle id from the
 *     request and returns where it is.
 *
 * The list is built in a fixed number of queries however many buses a parent
 * follows (the app polls it): followers with their bus and school in one
 * join, then stop places, live positions and open runs, each batch-loaded.
 */
final class FollowedBuses
{
    /** Search results a parent sees for one plate. Plates are unique; this is a ceiling, not a page. */
    public const SEARCH_LIMIT = 5;

    /**
     * School buses carrying this plate, as typed. Exact match on the
     * normalised plate -- the same comparison driver sign-in uses -- never a
     * contains-match: a parent who types "KC" must not get a list of every
     * school bus whose plate has a K and a C in it.
     *
     * @return Collection<int, object{id:int, plate:string, school_id:int, school_name:string}>
     */
    public function searchByPlate(string $typed): Collection
    {
        $normalised = VehicleAssignment::normalisePlate($typed);

        // "---" normalises to "" and `= ''` would match the legacy junk rows
        // with empty plates; see VehicleAssignment::matching().
        if ($normalised === '') {
            return collect();
        }

        return $this->activeSchoolBuses()
            ->whereRaw(PlateSql::normaliseColumn('vehicles.plate').' = ?', [$normalised])
            ->orderBy('vehicles.id')
            ->limit(self::SEARCH_LIMIT)
            ->get(['vehicles.id', 'vehicles.plate', 'saccos.id as school_id', 'saccos.name as school_name']);
    }

    /**
     * The active school bus with this id, carrying what a follow needs to
     * check the code. Null for an unknown id, a matatu, a bus that is off or a
     * school that is off -- one answer for all of them, so a caller cannot
     * tell which.
     */
    public function schoolBus(int $vehicleId): ?Vehicle
    {
        /** @var Vehicle|null */
        return $this->activeSchoolBuses()
            ->where('vehicles.id', $vehicleId)
            ->first(['vehicles.id', 'vehicles.sacco_id', 'vehicles.follow_code']);
    }

    /**
     * Is this place a stop on one of the school's routes?
     *
     * A school's routes are the ones it owns (routes.sacco_id) and the ones
     * linked to it in sacco_routes, the older pivot some fleets were set up
     * with. A stop is any route_stages place on them, or the route's own
     * origin or destination. At most two EXISTS probes, and
     * route_stages(place_id, route_id, distance) serves the first.
     */
    public function placeServesSchool(int $placeId, int $schoolId): bool
    {
        $schoolRoutes = fn (QueryBuilder $q) => $q->select('routes.id')
            ->from('routes')
            ->where('routes.sacco_id', $schoolId)
            ->orWhereIn('routes.id', fn (QueryBuilder $p) => $p->select('route_id')
                ->from('sacco_routes')->where('sacco_id', $schoolId));

        $isStage = DB::table('route_stages')
            ->where('place_id', $placeId)
            ->whereIn('route_id', $schoolRoutes)
            ->exists();

        return $isStage || DB::table('routes')
            ->whereIn('id', $schoolRoutes)
            ->where(fn (QueryBuilder $q) => $q->where('from_id', $placeId)->orWhere('to_id', $placeId))
            ->exists();
    }

    /**
     * The caller's followed buses (or the one given), ready for
     * FollowedBusResource: each follower carries bus_plate, school_id and
     * school_name, and the relations stopPlace, location (live only) and run
     * (open only). A follow on a bus that is no longer an active school bus
     * is left in place and simply not returned.
     *
     * @return Collection<int, VehicleFollower>
     */
    public function forUser(int $userId, ?int $vehicleId = null): Collection
    {
        $followers = self::confineToActiveSchools(VehicleFollower::query()
            ->join('vehicles', 'vehicles.id', '=', 'vehicle_followers.vehicle_id'))
            ->where('vehicle_followers.user_id', $userId)
            ->when($vehicleId !== null, fn (Builder $q) => $q->where('vehicle_followers.vehicle_id', $vehicleId))
            ->orderBy('vehicle_followers.id')
            // MAX_PER_USER bounds this already; the limit keeps it bounded
            // even if a row ever got past that check.
            ->limit(VehicleFollower::MAX_PER_USER)
            ->get([
                'vehicle_followers.id', 'vehicle_followers.user_id', 'vehicle_followers.vehicle_id',
                'vehicle_followers.stop_place_id', 'vehicle_followers.stop_latitude', 'vehicle_followers.stop_longitude',
                'vehicle_followers.created_at',
                'vehicles.plate as bus_plate', 'saccos.id as school_id', 'saccos.name as school_name',
            ]);

        if ($followers->isEmpty()) {
            return $followers;
        }

        $places = $this->stopPlaces($followers);
        $locations = $this->livePositions($followers->pluck('vehicle_id')->all());
        $runs = $this->openRuns($locations);

        return $followers->each(function (VehicleFollower $follower) use ($places, $locations, $runs) {
            $location = $locations->get((int) $follower->vehicle_id);
            $run = $location?->queue_id !== null ? $runs->get((int) $location->queue_id) : null;

            $follower->setRelation('stopPlace', $places->get((int) $follower->stop_place_id));
            $follower->setRelation('location', $location);
            // The run must be THIS bus's: a location row's queue_id is the
            // driver's ping, and a run is what a passenger books -- never
            // show one bus's trip on another.
            $follower->setRelation('run', $run !== null && (int) $run->vehicle_id === (int) $follower->vehicle_id ? $run : null);
        });
    }

    /** @return Collection<int, Place> keyed by id */
    private function stopPlaces(Collection $followers): Collection
    {
        $ids = $followers->pluck('stop_place_id')->filter()->unique()->values()->all();

        return $ids === []
            ? collect()
            : Place::query()->whereIn('id', $ids)->get(['id', 'name', 'latitude', 'longitude'])->keyBy('id');
    }

    /**
     * Positions inside the live window only. A stale row is not fetched at
     * all, so there is no path by which a parent sees where a bus WAS -- only
     * where it is while it is broadcasting (docs rule 2).
     *
     * @param  array<int, int>  $vehicleIds
     * @return Collection<int, VehicleLocation> keyed by vehicle_id
     */
    private function livePositions(array $vehicleIds): Collection
    {
        return VehicleLocation::withoutGlobalScopes()
            ->whereIn('vehicle_id', $vehicleIds)
            ->where('broadcasting', true)
            ->where('recorded_at', '>=', now()->subSeconds(VehicleLocationService::FRESH_SECONDS))
            ->get(['vehicle_id', 'queue_id', 'latitude', 'longitude', 'heading', 'speed_kmh', 'recorded_at'])
            ->keyBy('vehicle_id');
    }

    /**
     * The open live runs those positions point at, with their route's name.
     * Open is Pending or Active, as LiveRun reads it.
     *
     * @param  Collection<int, VehicleLocation>  $locations
     * @return Collection<int, object{id:int, vehicle_id:int, route_id:?int, route_name:?string}> keyed by queue id
     */
    private function openRuns(Collection $locations): Collection
    {
        $queueIds = $locations->pluck('queue_id')->filter()->unique()->values()->all();
        if ($queueIds === []) {
            return collect();
        }

        return DB::table('queues')
            ->join('queue_statuses', 'queue_statuses.id', '=', 'queues.queue_status_id')
            ->leftJoin('routes', 'routes.id', '=', 'queues.route_id')
            ->whereIn('queues.id', $queueIds)
            ->where('queues.kind', Queue::KIND_LIVE)
            ->whereIn('queue_statuses.status', ['Pending', 'Active'])
            ->get(['queues.id', 'queues.vehicle_id', 'queues.route_id', 'routes.name as route_name'])
            ->keyBy('id');
    }

    /** Vehicles that are on, in a school that is on. The only door a parent has to a bus. */
    private function activeSchoolBuses(): Builder
    {
        return self::confineToActiveSchools(Vehicle::withoutGlobalScopes());
    }

    /**
     * The one definition of "an active school bus", for any query that has
     * `vehicles` in it: the bus is on, and its SACCO is a school that is on.
     */
    private static function confineToActiveSchools(Builder $query): Builder
    {
        return $query
            ->join('saccos', 'saccos.id', '=', 'vehicles.sacco_id')
            ->where('saccos.kind', Sacco::KIND_SCHOOL)
            ->where('saccos.status', 1)
            ->where('vehicles.status', true);
    }
}
