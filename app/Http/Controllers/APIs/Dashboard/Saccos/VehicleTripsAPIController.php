<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Dashboard\Saccos;

use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Concerns\ScopesToOwnedVehicles;
use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Services\Sql\LikeSql;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * How many TRIPS each bus made in a window — and what it took while doing them.
 *
 * WHY THIS EXISTS AT ALL. Nothing in the platform counted journeys per vehicle.
 * The nearest thing on screen is `total_txn` on the summaries table, sitting one
 * column away from the plate, and every owner who has ever looked at it has read
 * it as "trips". It is not: it is PAYMENTS. On one production day NICCO MOVERS'
 * 143 active buses booked 13,313 of them — call it 93 a bus. Nobody drives
 * Nairobi–Thika 93 times between dawn and dusk. The number a matatu owner is
 * actually asking for is nearer 8, so the figure they have been reading is out
 * by roughly a hundredfold, in the flattering direction. This endpoint answers
 * the question that was being asked, with the money beside it so the one screen
 * settles both.
 *
 * WHAT COUNTS AS A TRIP. See TRIP_STATUSES.
 *
 * ONE QUERY, NOT ONE PER BUS. NICCO has 180 vehicles. Counting queues per
 * vehicle in PHP is 180 round trips for one screen, and the SACCO above it has
 * 227 users who can all open it. Both aggregates are computed by GROUP BY inside
 * derived tables and LEFT JOINed on, so the page costs one statement whatever
 * the fleet size.
 *
 * WHY DERIVED TABLES AND NOT TWO PLAIN JOINS. Joining `queues` and `summaries`
 * to `vehicles` directly and aggregating once would fan out: a bus with 8 queues
 * and 2 summary rows for the window produces 16 result rows, so the trip count
 * would read 16 and the day's takings would be doubled. Pre-aggregating each
 * side to one row per vehicle before the join is what makes both numbers true.
 *
 * TENANCY IS THE MODEL'S JOB. The base query is Vehicle, so SaccoScope,
 * BrandScope and FinancierScope all apply as written — a SACCO admin sees their
 * own fleet, a bank user sees only the buses their bank financed, a superadmin
 * sees the brand. Nothing here re-derives that. The two derived tables read
 * `queues` and `summaries` through the query builder and therefore carry no
 * scopes of their own; they do not need any, because they only ever contribute
 * columns to a `vehicles` row that already survived all three. A vehicle_id
 * belonging to another SACCO has nothing to join to.
 *
 * The permission gate on the route is NOT decorative, for the reason spelled out
 * on the summaries route: Vehicle opts into cross-tenant browsing (a passenger
 * must be able to find a bus from a SACCO they do not belong to), so SaccoScope
 * deliberately does not narrow a caller with no SACCO of their own. Without the
 * gate, any authenticated passenger would read every SACCO's trip counts, and
 * with the money columns attached, its takings.
 */
class VehicleTripsAPIController extends Controller
{
    use ScopesToOwnedVehicles;

    use PaginatesResults;

    /**
     * The queue statuses that mean "this bus actually made this journey".
     *
     * The lifecycle is Pending -> Active -> Completed, with Cancelled and
     * Suspended as exits (see QueueStatusSeeder). Of those five:
     *
     *   Completed  YES. The trip ran and ended. Uncontroversial.
     *   Active     YES. The bus has left the stage and is carrying passengers
     *              right now. Excluding it would mean an owner refreshing at
     *              14:00 cannot see the run currently underway — the count would
     *              lag reality by one trip per moving bus, all day, and only
     *              square up in the evening.
     *   Pending    NO. A place in the queue at the terminus. The bus is parked.
     *              Counting it turns "joined the stage" into "made a journey",
     *              which on a busy morning is exactly the inflation this
     *              endpoint was written to stop.
     *   Cancelled  NO. A driver who joined the stage and then pulled out. No
     *              passenger was carried and no fare was earned.
     *   Suspended  NO. A queue taken out of service. Same reasoning.
     *
     * This is deliberately the SAME definition DriverPortalController already
     * uses for its per-driver `trips` figure. Two screens in one product
     * disagreeing about what a trip is would be worse than either definition
     * being wrong, because neither number could then be checked against the
     * other.
     */
    private const TRIP_STATUSES = ['Completed', 'Active'];

    /**
     * Longest window this endpoint will price.
     *
     * The derived tables scan `queues` and `summaries` across the whole window
     * before the join narrows them to the caller's fleet, so an unbounded range
     * is an unbounded scan that one mistyped `from` can trigger. A year of
     * history is more than any operational question needs; anything longer is
     * refused out loud rather than served slowly, because a request that takes
     * ninety seconds and then times out at the proxy looks to the user like the
     * data is missing.
     */
    private const MAX_RANGE_DAYS = 366;

    /**
     * Sort keys the client may name, mapped to SQL in sortExpression().
     *
     * A whitelist, not a passthrough: `sort` ends up inside orderByRaw, and the
     * expressions for the two aggregate columns cannot be bound as parameters.
     */
    private const SORTABLE = ['trips', 'collections', 'plate', 'fleet_no'];

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request): JsonResponse
    {
        $range = $this->range($request);

        if (! $range instanceof \stdClass) {
            return $range;
        }

        [$from, $to] = [$range->from, $range->to];

        // The money columns ride on the SAME permission that guards the
        // summaries screen, and are simply absent otherwise. The route admits a
        // caller holding only 'View Sacco Vehicles' — a depot manager who runs
        // the fleet but is not trusted with the takings — and handing them
        // per-bus revenue here would be a quieter way of granting 'View
        // Summaries' than granting it. Absent, not zeroed: a 0 in a money column
        // reads as "this bus earned nothing today", which is a different and
        // much more alarming statement than "you may not see this".
        $caller = $request->user();

        // FINDING 3. The money columns come from DB::table('summaries') — a RAW
        // builder, so Summary's SaccoScope and FinancierScope never run, and the
        // only thing confining them is the join to `vehicles`. Vehicle
        // deliberately opts into cross-tenant browsing so passengers can find a
        // bus, which means SaccoScope does NOT narrow a caller whose sacco_id is
        // NULL. Unguarded, a saccoless non-super non-bank account holding View
        // Summaries would read per-vehicle takings for all 883 vehicles across
        // 18 SACCOs — and 6,388 accounts have a NULL sacco_id.
        //
        // Same shape QRCodeApiController already uses for the same reason: a
        // tenantless caller has no tenant to widen to, so the money is failed
        // closed rather than served.
        $money = (bool) $caller?->can('View Summaries')
            && ($caller->isSuperAdmin() || $caller->isBankUser() || $caller->currentSaccoId() !== null);

        // FINDING 2. The Investor bundle holds View Summaries, so without this
        // the endpoint hands an investor per-vehicle takings for all 180 of
        // NICCO's buses — finer-grained than the listings this batch just
        // narrowed. NULL leaves every other caller untouched; an EMPTY array is
        // passed through UNGATED and compiles to 0 = 1.
        $ownedVehicleIds = $this->ownedVehicleIds();

        $query = $this->baseQuery($request, $from, $to, $money);

        if ($ownedVehicleIds !== null) {
            $query->whereIn('vehicles.id', $ownedVehicleIds);
        }

        // Metadata off the UNPAGED query, before select/order/limit — the trait's
        // own warning: taken afterwards the total describes the page, not the
        // fleet.
        $perPage = $this->perPage($request);
        $meta = $this->pageMeta($query, $request, $perPage);
        $currentPage = max((int) $request->input('page', 1), 1);

        $totals = $this->totals($query, $money);

        $sort = $this->sortKey($request, $money);
        $rawDirection = $request->input('direction', 'desc');
        $direction = is_string($rawDirection) && strtolower(trim($rawDirection)) === 'asc' ? 'asc' : 'desc';

        $rows = (clone $query)
            ->select(array_merge([
                'vehicles.id',
                'vehicles.plate',
                'vehicles.fleet_no',
                'vehicles.sacco_id',
                'saccos.name as sacco_name',
                DB::raw('COALESCE(trip_counts.trips, 0) as trips'),
            ], $money ? [
                DB::raw('COALESCE(day_money.mpesa_amount, 0) as mpesa_amount'),
                DB::raw('COALESCE(day_money.cash_amount, 0) as cash_amount'),
            ] : []))
            ->orderByRaw($this->sortExpression($sort, $money).' '.$direction)
            // Deterministic tie-breaker. Most of a fleet ties on trips — 37 of
            // NICCO's 180 buses did not move at all on the day measured, so they
            // all tie on 0 — and without a stable second key PostgreSQL is free
            // to return tied rows in a different order for page 1 and page 2.
            // The reader then sees the same bus twice and never sees another.
            ->orderBy('vehicles.id', 'asc')
            ->skip(($currentPage - 1) * $perPage)
            ->take($perPage)
            ->get();

        return response()->json(array_merge([
            'vehicles' => $rows->map(fn ($v): array => $this->row($v, $money))->values(),
            'totals' => $totals,
            'range' => ['from' => $from->toDateString(), 'to' => $to->copy()->subDay()->toDateString()],
            'sort' => $sort,
            'direction' => $direction,
            // Published on purpose. `total_txn` on the summaries screen is
            // misread as trips precisely because nothing next to it says what it
            // counts; this one says so, in the payload, where a dashboard can
            // print it under the column heading.
            'trip_statuses' => self::TRIP_STATUSES,
            'includes_money' => $money,
        ], $meta));
    }

    /**
     * Vehicles, with the window's trips and takings pre-aggregated onto each.
     *
     * Everything the endpoint returns — the page, the footer and the page count
     * — is built from this one builder, so a filter cannot reach the rows but
     * miss the total beneath them.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Vehicle>
     */
    private function baseQuery(Request $request, Carbon $from, Carbon $to, bool $money)
    {
        $query = Vehicle::query()
            // Left, not inner: a vehicle whose sacco_id points at a row that no
            // longer exists must still be counted, or a bus quietly vanishes
            // from the fleet total because of an unrelated data problem.
            ->leftJoin('saccos', 'saccos.id', '=', 'vehicles.sacco_id')
            ->leftJoinSub($this->tripCounts($from, $to), 'trip_counts', 'trip_counts.vehicle_id', '=', 'vehicles.id');

        if ($money) {
            $query->leftJoinSub($this->dayMoney($from, $to), 'day_money', 'day_money.vehicle_id', '=', 'vehicles.id');
        }

        // A display filter, not a boundary — SaccoScope has already decided
        // which SACCOs are reachable. It exists for the superadmin and bank
        // tiers, who legitimately look at one SACCO's fleet inside a wider set.
        //
        // is_scalar, not a bare cast: `?sacco[]=1` arrives as an ARRAY, which
        // casts to int 1 and would silently filter to SACCO 1 — and reaches PDO
        // as a 500 if bound raw.
        $sacco = $request->input('sacco');
        if (is_scalar($sacco) && (int) $sacco > 0) {
            $query->where('vehicles.sacco_id', (int) $sacco);
        }

        // Qualified on purpose: `plate` is unambiguous today, but `status` is a
        // name three tables in this join graph share, and an unqualified one
        // already caused a production bug in the fare matrix.
        $search = $request->input('search');
        if (is_string($search) && trim($search) !== '') {
            $term = '%'.$search.'%';
            $query->where(function ($q) use ($term): void {
                $q->where('vehicles.plate', LikeSql::op(), $term)
                    ->orWhere('vehicles.fleet_no', LikeSql::op(), $term);
            });
        }

        // Default is to list the WHOLE fleet, including buses that did nothing.
        // "Which of my buses did no trips today?" is the same owner's next
        // question and the more expensive one to get wrong — a bus missing from
        // the list looks like a bus that is fine. `only_with_trips` is for the
        // caller who wants the busy ones alone.
        if ($request->boolean('only_with_trips')) {
            $query->whereNotNull('trip_counts.vehicle_id');
        }

        return $query;
    }

    /**
     * One row per vehicle: how many real trips it ran in the window.
     *
     * Attributed by `created_at` — the moment the bus joined the stage — not by
     * when the trip ended. A run that starts at 22:40 and completes after
     * midnight therefore belongs to the day it set off, which is the day its
     * driver and its fares belong to. DriverPortalController counts the same way,
     * so the per-driver and per-vehicle figures reconcile.
     */
    private function tripCounts(Carbon $from, Carbon $to): QueryBuilder
    {
        return DB::table('queues')
            ->join('queue_statuses', 'queue_statuses.id', '=', 'queues.queue_status_id')
            // queue_statuses.status is the enum the whole codebase keys on;
            // queue_statuses.name is the editable label. Qualified because
            // `queues` gained columns before and `status` is a name three tables
            // in this join graph are entitled to use.
            ->whereIn('queue_statuses.status', self::TRIP_STATUSES)
            // Half-open [from, to). An inclusive BETWEEN counts a queue created
            // at exactly midnight into both the day that ended and the day that
            // started.
            ->where('queues.created_at', '>=', $from)
            ->where('queues.created_at', '<', $to)
            ->groupBy('queues.vehicle_id')
            ->select('queues.vehicle_id', DB::raw('COUNT(*) as trips'));
    }

    /**
     * One row per vehicle: the window's takings.
     *
     * summaries is one row per bus per day, so a single date needs no SUM — but
     * a range does, and the same expression has to serve both. Read from
     * summaries rather than transactions for the same reason the money screen
     * does: it is the pre-aggregated table, 1/100th the rows.
     *
     * `expense_fee_amount` is deliberately not carried here. It is a string
     * column with a '' problem that the summaries controller has to CAST around,
     * and this screen answers "how much did it take", not "what did it keep".
     */
    private function dayMoney(Carbon $from, Carbon $to): QueryBuilder
    {
        return DB::table('summaries')
            // trans_date is a DATE, so compare against dates: handing it a
            // timestamp makes PostgreSQL widen the column to compare, and the
            // index on trans_date stops being usable.
            ->where('summaries.trans_date', '>=', $from->toDateString())
            ->where('summaries.trans_date', '<', $to->toDateString())
            ->groupBy('summaries.vehicle_id')
            ->select(
                'summaries.vehicle_id',
                DB::raw('SUM(summaries.mpesa_amount) as mpesa_amount'),
                DB::raw('SUM(summaries.cash_amount) as cash_amount'),
            );
    }

    /**
     * The footer, over the WHOLE filtered fleet rather than the visible page.
     *
     * One extra statement, not one per row: the derived tables are already
     * joined and already one row per vehicle, so this is a plain aggregate over
     * the same builder. A footer that changed as you paged would be read as the
     * numbers moving.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Vehicle>  $query
     * @return array<string, float|int>
     */
    private function totals($query, bool $money): array
    {
        $row = (clone $query)->select(array_merge([
            DB::raw('COUNT(*) as vehicles_counted'),
            DB::raw('COALESCE(SUM(COALESCE(trip_counts.trips, 0)), 0) as trips_total'),
            // How many buses actually moved. 143 of NICCO's 180 on the day
            // measured — the difference between that and `vehicles_counted` is
            // itself an answer the owner wants.
            DB::raw('COUNT(trip_counts.vehicle_id) as vehicles_with_trips'),
        ], $money ? [
            DB::raw('COALESCE(SUM(COALESCE(day_money.mpesa_amount, 0)), 0) as mpesa_total'),
            DB::raw('COALESCE(SUM(COALESCE(day_money.cash_amount, 0)), 0) as cash_total'),
        ] : []))->first();

        $totals = [
            'vehicles' => (int) $row->vehicles_counted,
            'vehicles_with_trips' => (int) $row->vehicles_with_trips,
            'trips' => (int) $row->trips_total,
        ];

        if ($money) {
            $mpesa = (float) $row->mpesa_total;
            $cash = (float) $row->cash_total;
            $totals += [
                'mpesa_amount' => $mpesa,
                'cash_amount' => $cash,
                'collections' => $mpesa + $cash,
            ];
        }

        return $totals;
    }

    /**
     * One response row.
     *
     * Cast explicitly. PostgreSQL hands SUM(double) back as a string through
     * PDO, and COUNT(*) as a string too, so an untouched payload would ship
     * `"trips": "8"` — which sorts as text in any client that trusts it, putting
     * 10 before 9.
     *
     * @return array<string, mixed>
     */
    private function row($vehicle, bool $money): array
    {
        $row = [
            'id' => (int) $vehicle->id,
            'plate' => $vehicle->plate,
            'fleet_no' => $vehicle->fleet_no,
            'sacco_id' => $vehicle->sacco_id === null ? null : (int) $vehicle->sacco_id,
            'sacco_name' => $vehicle->sacco_name,
            'trips' => (int) $vehicle->trips,
        ];

        if ($money) {
            $mpesa = (float) $vehicle->mpesa_amount;
            $cash = (float) $vehicle->cash_amount;
            $row += [
                'mpesa_amount' => $mpesa,
                'cash_amount' => $cash,
                'collections' => $mpesa + $cash,
            ];
        }

        return $row;
    }

    /** The requested sort, or the one this endpoint exists to answer. */
    private function sortKey(Request $request, bool $money): string
    {
        $sort = $request->input('sort', 'trips');
        $sort = is_string($sort) ? strtolower(trim($sort)) : '';

        if (! in_array($sort, self::SORTABLE, true)) {
            return 'trips';
        }

        // Sorting by a column the caller may not see would leak its ordering:
        // page 1 would be the top earners, named.
        if ($sort === 'collections' && ! $money) {
            return 'trips';
        }

        return $sort;
    }

    /**
     * SQL for a whitelisted sort key.
     *
     * COALESCE is load-bearing on the two aggregate sorts, not tidiness. The
     * LEFT JOIN leaves NULL for a bus with no queues in the window, and
     * PostgreSQL orders NULLS FIRST on DESC. "Busiest bus first" would have
     * opened on the 37 NICCO buses that never left the yard, and the answer to
     * the question — the busiest bus — would have been on page 2.
     */
    private function sortExpression(string $sort, bool $money): string
    {
        return match ($sort) {
            'plate' => 'vehicles.plate',
            'fleet_no' => 'vehicles.fleet_no',
            'collections' => $money
                ? '(COALESCE(day_money.mpesa_amount, 0) + COALESCE(day_money.cash_amount, 0))'
                : 'COALESCE(trip_counts.trips, 0)',
            default => 'COALESCE(trip_counts.trips, 0)',
        };
    }

    /**
     * The window, in the same parameters every money screen already takes:
     * `date` for one day, or `from`/`to` for a range. Returns [from, to) with an
     * EXCLUSIVE upper bound.
     *
     * Copied from SummariesAPIController::range() rather than shared, and
     * deliberately: that method sits inside a live takings endpoint, and pulling
     * it out to change what the money screen computes is not a change this
     * feature is entitled to make. If a third caller appears, extract it then —
     * with the summaries tests in front of you. Any edit here must be mirrored
     * there, or two screens will disagree about what "today" means.
     *
     * @return \stdClass|JsonResponse {from: Carbon, to: Carbon}, or a 400.
     */
    private function range(Request $request)
    {
        if (filled($request->from) || filled($request->to)) {
            try {
                $from = Carbon::parse($request->input('from', $request->input('to')))->startOfDay();
                $to = Carbon::parse($request->input('to', $request->input('from')))->startOfDay()->addDay();
            } catch (\Throwable $e) {
                // Carbon::parse throws on junk. Unhandled it is a 500, which
                // reads as "the server is broken" for what is a typo.
                return response()->json(['error' => 'from/to must be dates (YYYY-MM-DD).'], 400);
            }
        } else {
            try {
                $from = filled($request->date) ? Carbon::parse($request->date)->startOfDay() : Carbon::today();
            } catch (\Throwable $e) {
                return response()->json(['error' => 'date must be a date (YYYY-MM-DD).'], 400);
            }
            $to = $from->copy()->addDay();
        }

        // A backwards range is a swapped pair of inputs, not a request for zero
        // days. Matching summaries, it collapses to the single day named.
        if ($to->lessThanOrEqualTo($from)) {
            $to = $from->copy()->addDay();
        }

        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            return response()->json([
                'error' => 'Range too large. Ask for at most '.self::MAX_RANGE_DAYS.' days.',
            ], 400);
        }

        return (object) ['from' => $from, 'to' => $to];
    }
}
