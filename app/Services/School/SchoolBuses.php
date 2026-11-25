<?php

declare(strict_types=1);

namespace App\Services\School;

use App\Models\Sacco;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The one place that knows which vehicles are school buses.
 *
 * A school bus is a vehicle whose SACCO has kind 'school'
 * (docs/school-bus-tracking.md). It is never offered to passengers -- not in
 * the trip list, nearby, the route catalogue, seats, booking or roadside
 * reserve -- and every one of those paths asks HERE, so "is this a school
 * bus" cannot drift between them.
 *
 * Two shapes, for two kinds of caller:
 *
 *  - Queries add a NOT EXISTS on saccos.kind. Correlated on the primary keys
 *    of vehicles and saccos, so it costs two index probes per row (or one
 *    hashed anti-join) and never a PHP-side list of ids that grows with the
 *    number of schools. NOT EXISTS rather than NOT IN because a NULL in the
 *    outer column must keep the row, exactly as it did before schools.
 *
 *  - The GPS ping path (VehicleMoved, ArrivalAlerts) asks about one vehicle
 *    on every ping, every few seconds, for every bus on the road. That answer
 *    is cached per vehicle so a matatu pays no query for schools to exist.
 *    A bus does not change SACCO in the middle of a run; whoever moves one
 *    calls forget().
 */
final class SchoolBuses
{
    /** How long the per-vehicle answer is trusted. */
    public const CACHE_SECONDS = 600;

    /**
     * Keep only rows whose vehicle is NOT a school bus.
     *
     * $vehicleColumn is the qualified column holding the vehicle id, e.g.
     * 'queues.vehicle_id' or 'vehicle_locations.vehicle_id'.
     *
     * @template T of EloquentBuilder|QueryBuilder|Relation
     *
     * @param  T  $query
     * @return T
     */
    public static function excludeFrom(EloquentBuilder|QueryBuilder|Relation $query, string $vehicleColumn): EloquentBuilder|QueryBuilder|Relation
    {
        $query->whereNotExists(function (QueryBuilder $q) use ($vehicleColumn): void {
            $q->select(DB::raw(1))
                ->from('vehicles as school_bus_vehicle')
                ->join('saccos as school_bus_sacco', 'school_bus_sacco.id', '=', 'school_bus_vehicle.sacco_id')
                ->whereColumn('school_bus_vehicle.id', $vehicleColumn)
                ->where('school_bus_sacco.kind', Sacco::KIND_SCHOOL);
        });

        return $query;
    }

    /**
     * Keep only rows whose SACCO is NOT a school: the route catalogue, where a
     * school's own routes ("Morning run") are not journeys anyone can book.
     * A NULL SACCO (an unowned route) is kept.
     *
     * @template T of EloquentBuilder|QueryBuilder|Relation
     *
     * @param  T  $query
     * @return T
     */
    public static function excludeSchoolSaccos(EloquentBuilder|QueryBuilder|Relation $query, string $saccoColumn): EloquentBuilder|QueryBuilder|Relation
    {
        $query->whereNotExists(function (QueryBuilder $q) use ($saccoColumn): void {
            $q->select(DB::raw(1))
                ->from('saccos as school_sacco')
                ->whereColumn('school_sacco.id', $saccoColumn)
                ->where('school_sacco.kind', Sacco::KIND_SCHOOL);
        });

        return $query;
    }

    /**
     * Is this vehicle a school bus? Cached per vehicle for CACHE_SECONDS, for
     * the paths that run on every ping. Anything deciding what a passenger may
     * see or book uses excludeFrom() instead, which is never stale.
     */
    public static function isSchoolBus(int $vehicleId): bool
    {
        if ($vehicleId <= 0) {
            return false;
        }

        // Stored as 0/1 rather than a bare bool so a cached "no" can never be
        // mistaken for a miss by any store.
        return (bool) Cache::remember(self::cacheKey($vehicleId), self::CACHE_SECONDS, static fn (): int => DB::table('vehicles')
            ->join('saccos', 'saccos.id', '=', 'vehicles.sacco_id')
            ->where('vehicles.id', $vehicleId)
            ->where('saccos.kind', Sacco::KIND_SCHOOL)
            ->exists() ? 1 : 0);
    }

    /** Drop the cached answer: call when a vehicle changes SACCO, or a SACCO changes kind. */
    public static function forget(int $vehicleId): void
    {
        Cache::forget(self::cacheKey($vehicleId));
    }

    private static function cacheKey(int $vehicleId): string
    {
        return 'school-bus:v1:'.$vehicleId;
    }
}
