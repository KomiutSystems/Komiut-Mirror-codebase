<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Composite indexes for the OPERATIONS domain (fleet, trips, bookings, routes).
 *
 * WHY THIS IS NEEDED AT ALL: almost every column below is a foreign key declared
 * with `foreignIdFor()`, which in Laravel creates only an unsignedBigInteger —
 * no index, and (where `constrained()` was used) only a constraint. PostgreSQL,
 * unlike MySQL, does NOT index the referencing side of a foreign key. So before
 * this migration `queues.vehicle_id`, `bookings.queue_id`, `vehicle_users.*`,
 * `sacco_routes.sacco_id` and friends were all completely unindexed, and the
 * hottest reads in the platform — the driver's crew check on every GPS ping, the
 * seat-availability check on every booking — were sequential scans.
 *
 * Column order follows one rule: equality predicates first, then the range or
 * sort column. Where every predicate is equality the order is chosen so the
 * index's leading prefix also serves the narrower queries.
 *
 * Deliberately NOT touched: `vehicle_locations`. See the note at the bottom.
 *
 * LOCKING — WHY `$withinTransaction = false` IS HERE. DO NOT REMOVE IT.
 *
 * A plain `CREATE INDEX` takes a SHARE lock on the table: reads continue but
 * every INSERT blocks until the build finishes. `queues`, `bookings` and
 * `vehicle_users` are all on live write paths (GPS pings, seat holds, crew
 * rotations), so on PostgreSQL every index below is built with
 * `CREATE INDEX CONCURRENTLY`, which takes no blocking lock.
 *
 * `CONCURRENTLY` cannot run inside a transaction block, and Laravel wraps each
 * migration in one on PostgreSQL. `public $withinTransaction = false` is what
 * disables that wrapper. Delete it and every `addIndex()` call below fails with
 * "CREATE INDEX CONCURRENTLY cannot run inside a transaction block"; the same
 * holds for `DROP INDEX CONCURRENTLY` in `down()`.
 *
 * The cost of leaving the transaction is that a mid-way failure is not rolled
 * back: some indexes exist and the migration is not recorded. `IF NOT EXISTS`
 * makes the re-run skip what already exists, and `dropIfInvalid()` first removes
 * any namesake a previously FAILED concurrent build left with
 * `pg_index.indisvalid = false` — such an index is never read by the planner but
 * is still maintained on every write, and `IF NOT EXISTS` matches on name alone
 * so it would otherwise be skipped over forever. (An index another session is
 * building CONCURRENTLY right now is also `indisvalid = false`, so do not run
 * two copies of this migration concurrently.)
 *
 * sqlite and MySQL do not support this syntax and keep the portable
 * Schema-builder path, which yields identical index names and columns.
 */
return new class extends Migration
{
    /**
     * Disables Laravel's per-migration transaction. Required by
     * CREATE/DROP INDEX CONCURRENTLY. See the class docblock before touching.
     */
    public $withinTransaction = false;

    /**
     * table => [index name => columns], in creation order.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private const INDEXES = [
        'vehicles' => [
            // SaccoScope appends `vehicles.sacco_id = ?` to every vehicle query
            // for a SACCO admin, and Dashboard\Vehicles\VehiclesAPIController::
            // getVehicles + Super\Vehicles\VehiclesController::index both add an
            // explicit `?sacco=` filter, then `ORDER BY created_at DESC LIMIT 20`.
            // sacco_id is equality so it leads; created_at trails so the page can
            // be read straight off the index in order instead of sorting the
            // SACCO's whole fleet. The plate/till LIKE '%..%' filter is applied
            // as a heap filter on that already-ordered walk.
            'vehicles_sacco_id_created_at_index' => ['sacco_id', 'created_at'],
        ],

        'vehicle_users' => [
            // THE HOTTEST READ IN THE DOMAIN. VehicleLocationController::crews()
            // and TripManifestController::crews() run
            //   EXISTS (vehicle_id = ? AND user_id = ? AND status = true)
            // on EVERY driver GPS ping and every manifest read. All three
            // predicates are equality, so any order works for that query; this
            // order is chosen because the (vehicle_id, status) PREFIX also fully
            // serves VehicleUsersAPIController::addVehicleUser and
            // Services\Driver\VehicleAssignment::releaseOtherDriversFromVehicle,
            // which look up the open assignment(s) on a vehicle. Putting user_id
            // second instead would leave those two with a one-column prefix.
            // (VehicleUser is soft-deleting, so every query also carries
            // `deleted_at IS NULL`; that stays a cheap heap filter — Laravel's
            // Schema builder cannot express a partial index.)
            'vehicle_users_vehicle_status_user_index' => ['vehicle_id', 'status', 'user_id'],

            // The mirror lookup: "which vehicle is this driver on right now" —
            // DriverQueueController::activeAssignment, QueuesAPIController::
            // getQueuesPlaces + getGeofence, AuthController's login payload,
            // ExpenseAndFeesAPIController, VehicleAssignment::openAssignments.
            // All are `user_id = ? AND status = true` (most also
            // `end_date IS NULL`, left as a heap filter: a driver has only a
            // handful of assignment rows once user_id has narrowed the set).
            'vehicle_users_user_id_status_index' => ['user_id', 'status'],

            // VehicleUser carries its own sacco_id, so SaccoScope filters
            // `vehicle_users.sacco_id = ?` directly (no whereHas). getVehicleUsers
            // then does `ORDER BY created_at DESC LIMIT 20`. Equality column
            // first, sort column second — same shape as the vehicles index above.
            // This table grows with every crew rotation, so the unindexed scan
            // it replaces gets worse over time.
            'vehicle_users_sacco_id_created_at_index' => ['sacco_id', 'created_at'],
        ],

        'queues' => [
            // THE ONE THAT MATTERS MOST. Super\Vehicles\VehiclesController::index
            // attaches a correlated subselect per vehicle row:
            //   SELECT MAX(start_time) FROM queues WHERE queues.vehicle_id = vehicles.id
            // With no index on vehicle_id that is one FULL SCAN of queues per
            // vehicle on the page — 25 sequential scans of the largest
            // operational table for a single admin page load. This is exactly the
            // failure shape that crippled the sibling system's 20.5M-row table.
            // vehicle_id equality leads; start_time trails so MAX() becomes a
            // backward index-only scan that stops on the first entry.
            // The same vehicle_id prefix also bounds DriverQueueController::
            // currentQueue, QueuesAPIController::addQueue's duplicate check and
            // VehicleAssignment::cancelOpenQueues, all of which were full scans.
            'queues_vehicle_id_start_time_index' => ['vehicle_id', 'start_time'],

            // BookARideQueuesAPIController::getQueues — the passenger-facing
            // "trips available now" list:
            //   WHERE queue_status_id IN (<Active>, <Pending>)
            //   ORDER BY queues.created_at DESC LIMIT 20
            // The live statuses are a small minority of all queues (historical
            // rows are Completed/Cancelled), so queue_status_id is selective;
            // created_at trails so PostgreSQL can walk each IN branch in
            // descending order and merge, rather than sorting every live queue.
            'queues_status_created_at_index' => ['queue_status_id', 'created_at'],

            // Dashboard\Queues\QueuesAPIController::getQueues always bounds to a
            // single day (`whereBetween('created_at', [$date, $date+1])`), and
            // addQueue's queue-number counter does the same for today. A single
            // range column, so nothing precedes it. NOTE: this bounds the SCAN
            // only — it cannot remove the sort, because that endpoint emits
            // `ORDER BY queue_number ASC, created_at DESC` (two orderBy calls
            // accumulate) and no index matches that pair.
            'queues_created_at_index' => ['created_at'],
        ],

        'bookings' => [
            // Services\Booking\SegmentSeatAvailability::occupiedSeatIds runs
            //   WHERE queue_id = ? AND status = true
            // on EVERY seat-map render and EVERY booking attempt (inside the
            // locked transaction, so an unindexed scan here holds the row lock
            // longer and serialises the whole queue). TripManifestController and
            // DriverQueueController use the same pair. Both predicates are
            // equality; queue_id leads because it is the selective one and its
            // prefix alone serves pickPassengers' `queue_id = ? AND from_id = ?`.
            // The `(paid = true OR created_at >= cutoff)` hold window is an OR of
            // two different columns and is not indexable — it runs as a filter on
            // the handful of rows one queue has.
            'bookings_queue_id_status_index' => ['queue_id', 'status'],

            // BookingsAPIController::getPassengerBookings and QueuesAPIController
            // list a single day: `whereBetween('created_at', [$date, $date+1])`
            // then `ORDER BY created_at DESC LIMIT 20`. One range column that is
            // also the sort column, so it both bounds the scan and removes the
            // sort.
            'bookings_created_at_index' => ['created_at'],

            // The two scheduled sweeps — ReleaseExpiredBookings
            // (`status = true AND paid = false AND created_at < cutoff`) and
            // CheckPassengerPayments (`paid = 0 AND created_at <= cutoff`).
            // These need paid to LEAD: `created_at < cutoff` matches nearly the
            // whole table, so bookings_created_at_index above is useless to them,
            // whereas unpaid holds are a small, self-limiting set (this very
            // command retires them). paid equality first, created_at range
            // second. Not a bare `paid` index — a boolean alone is too
            // low-selectivity to be worth its write cost.
            'bookings_paid_created_at_index' => ['paid', 'created_at'],
        ],

        'parcels' => [
            // BookingsAPIController::getParcels always bounds to one day
            // (`whereBetween('created_at', ...)`) and sorts
            // `ORDER BY created_at DESC LIMIT 20`. Everything else on that
            // endpoint is either an optional id filter applied to that bounded
            // set, or an unindexable LIKE '%..%' over the sender/recipient text.
            'parcels_created_at_index' => ['created_at'],
        ],

        'routes' => [
            // BookARideRoutesAPIController::getRoutes ends every call with
            //   WHERE routes.status = true ORDER BY routes.name ASC LIMIT 20
            // status is equality so it leads even though it is low-selectivity —
            // the point of the composite is that name trails it, so the page
            // comes off the index already ordered instead of sorting every
            // active route. (A lone `status` index would be exactly the
            // low-selectivity single-column index worth skipping; as a prefix of
            // this pair it earns its place.)
            'routes_status_name_index' => ['status', 'name'],
        ],

        'route_stages' => [
            // The route-segment SELF-JOIN in BookARideRoutesAPIController::
            // getRoutes, BookARideQueuesAPIController::getQueues and
            // BookARideSaccoRoutesAPIController::getSaccoRoutes:
            //   JOIN route_stages pickup  ON pickup.route_id  = routes.id
            //   JOIN route_stages dropoff ON dropoff.route_id = pickup.route_id
            //                            AND pickup.distance  < dropoff.distance
            //   WHERE pickup.place_id = ? AND dropoff.place_id = ?
            // The DROPOFF side is the expensive one: place_id equality, route_id
            // equality (bound from the pickup row), distance range. Hence exactly
            // this order — the two equalities first, the range last, so all three
            // predicates are resolved inside one index descent per pickup row.
            // The existing (route_id, distance) index can only apply route_id +
            // distance and then re-check place_id from the heap.
            //
            // This also fully serves RouteAPIController::addRouteStage's
            // `route_id = ? AND place_id = ?` duplicate check.
            //
            // NOTE: this makes the existing single-column `route_stages_place_id_index`
            // redundant — it is a strict prefix of this one. It is NOT dropped
            // here (this migration is additive); the drop lives in
            // 2026_08_07_123000_drop_redundant_indexes, which verifies at runtime
            // that this index exists before removing the narrower one.
            'route_stages_place_route_distance_index' => ['place_id', 'route_id', 'distance'],
        ],

        'sacco_routes' => [
            // SaccoRoute carries its own sacco_id, so SaccoScope filters
            // `sacco_routes.sacco_id = ?` directly. On top of that:
            //   Services\Fares\FareResolver::bundle  — sacco_id = ? AND route_id = ?
            //                                          AND status = true → value(amount)
            //   RouteAPIController::getRoutes        — sacco_id = ? → pluck(route_id),
            //                                          on every routes list
            //   RouteAPIController::addRoute         — firstOrCreate(sacco_id, route_id)
            //   Super\Saccos\*                       — sacco_id = ? → distinct route_id
            // Both are equality, and the pair is effectively unique (firstOrCreate
            // treats it as such), so `status` is omitted — it would not narrow a
            // single row. sacco_id leads so its prefix serves the pluck/count
            // forms. Not declared UNIQUE: existing production rows may already
            // contain duplicate pairs and the migration must not fail on them.
            'sacco_routes_sacco_id_route_id_index' => ['sacco_id', 'route_id'],
        ],

        'sacco_termini' => [
            // TerminusAPIController::getTermini and QueuesAPIController::
            // getGeofence both open with `sacco_id = ?` → pluck(terminus_id);
            // IndexApiController uses `terminus_id = ? AND sacco_id = ?`. Two
            // equality columns; sacco_id leads because that is the prefix the two
            // hot reads use, and terminus_id trails so the pluck is index-only
            // (no heap visit at all). Small table, but written only when a SACCO
            // is onboarded to a terminus, so the write cost is nil.
            'sacco_termini_sacco_id_terminus_id_index' => ['sacco_id', 'terminus_id'],
        ],

        /*
         * NOTHING IS ADDED TO `vehicle_locations`, ON PURPOSE.
         *
         * It already has UNIQUE(vehicle_id), (broadcasting, recorded_at) and
         * (latitude, longitude), and it is upserted on every driver GPS ping.
         * Because latitude/longitude/recorded_at/broadcasting are all indexed AND
         * all change on every ping, PostgreSQL can never take the HOT-update path
         * here: each ping already writes a new entry into all three indexes plus
         * the primary key. A fourth index is a ~25% write amplification on the
         * single most write-hammered table in the domain.
         *
         * The candidate would have been `recorded_at` alone, for
         * VehicleLocationsReadController's `ORDER BY recorded_at DESC LIMIT 1000`.
         * It is not worth it: this table holds ONE ROW PER VEHICLE, so its
         * cardinality is bounded by fleet size (thousands), not by ping count.
         * Sorting a few thousand rows is free; paying for it on every ping is not.
         *
         * VehicleLocationService::nearby() is likewise already served as well as a
         * btree can: (broadcasting, recorded_at) is equality-then-range, exactly
         * right, and it narrows to the live fleet of the last 120 seconds before
         * the bounding box is even considered. The existing (latitude, longitude)
         * can only ever use `latitude` for index selection — a second range column
         * degrades to a filter — but it exists already and needs no companion.
         * A route_id index was also rejected: it is an optional filter applied to
         * that already-tiny live set.
         */
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                $this->addIndex($table, $name, $columns);
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                $this->dropIndexIfExists($table, $name);
            }
        }
    }

    /**
     * Create $name on $table without blocking writes.
     *
     * PostgreSQL: `CREATE INDEX CONCURRENTLY IF NOT EXISTS`, preceded by the
     * INVALID-namesake sweep described in the class docblock.
     * Everything else: the portable Schema-builder path — same name, same
     * columns, same order.
     */
    private function addIndex(string $table, string $name, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            if (Schema::hasIndex($table, $name)) {
                return;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($columns, $name): void {
                $blueprint->index($columns, $name);
            });

            return;
        }

        $this->dropIfInvalid($name);

        DB::unprepared(sprintf(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (%s)',
            $this->quote($name),
            $this->quote($table),
            implode(', ', array_map($this->quote(...), $columns)),
        ));
    }

    /**
     * Remove $name, undoing exactly what addIndex() created.
     *
     * `DROP INDEX CONCURRENTLY` also cannot run inside a transaction, which is
     * the second reason `$withinTransaction = false` must stay.
     */
    private function dropIndexIfExists(string $table, string $name): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP INDEX CONCURRENTLY IF EXISTS '.$this->quote($name));

            return;
        }

        if (! Schema::hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name): void {
            $blueprint->dropIndex($name);
        });
    }

    /**
     * Drop $name if a previous failed CONCURRENTLY build left it INVALID.
     *
     * Without this, `IF NOT EXISTS` — which matches on name only — would skip
     * the broken index forever: never used for reads, still maintained on every
     * write. PostgreSQL-only; called only from the pgsql branch.
     */
    private function dropIfInvalid(string $name): void
    {
        $invalid = DB::select(
            'select 1
               from pg_index i
               join pg_class c on c.oid = i.indexrelid
               join pg_namespace n on n.oid = c.relnamespace
              where n.nspname = current_schema()
                and c.relname = ?
                and i.indisvalid = false',
            [$name],
        );

        if ($invalid !== []) {
            DB::unprepared('DROP INDEX CONCURRENTLY IF EXISTS '.$this->quote($name));
        }
    }

    /**
     * Quote a PostgreSQL identifier. Every name here is lower-case ASCII, so
     * this is belt-and-braces rather than strictly necessary — but these strings
     * are interpolated into DDL, so they are quoted and escaped on principle.
     */
    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
};
