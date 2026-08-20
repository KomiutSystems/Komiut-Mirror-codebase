<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Driver;

use App\Http\Controllers\Concerns\ResolvesDriverVehicle;
use App\Http\Controllers\Controller;
use App\Models\Queue;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Driver — trip history
 *
 * The list the current-trip endpoint never gave. `driver/trip` only ever
 * returns the ONE queue a bus is running right now, so a driver could see what
 * they are on but never what they have already run. This is the past: every
 * queue this VEHICLE has completed or cancelled, newest first, each with its
 * status and what it took.
 *
 * Keyed on the vehicle resolved from the caller's open assignment, never on the
 * driver — crews rotate between matatus and a queue belongs to the bus that ran
 * it, not the person who was aboard. Read-only and scoped by IDENTITY: the
 * assignment IS the authorization boundary, so nothing here takes a vehicle id
 * from the request, and a driver can only ever read their own bus's history.
 */
class DriverTripHistoryController extends Controller
{
    use ResolvesDriverVehicle;

    /** Fixed page size, so a phone on a matatu route cannot ask for 5,000. */
    private const PER_PAGE = 20;

    /**
     * The queue_status.status vocabulary a trip can carry. The two states a
     * driver reviews — completed | cancelled — are the only accepted ?status=
     * filters; the live pair is exposed too so the app can label any row.
     *
     * @var array<string, string>
     */
    private const STATUSES = [
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'active' => 'Active',
        'pending' => 'Pending',
    ];

    /** The subset of STATUSES accepted as a ?status= filter. */
    private const FILTERABLE = ['completed', 'cancelled'];

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Past trips for the caller's vehicle
     *
     * Paginated at 20, newest first. `takings` is the sum of each queue's PAID
     * bookings — one correlated aggregate per row via withSum, not a query per
     * trip. Optional ?status=completed|cancelled narrows to one lifecycle state;
     * anything else leaves the list whole.
     */
    public function index(Request $request): JsonResponse
    {
        $vehicle = $this->vehicle();
        if ($vehicle === null) {
            return $this->noAssignment();
        }

        $query = Queue::query()
            // Eager-load the pieces every row renders, so the map below fires no
            // per-trip query: the status word, and the route's end places.
            ->with(['queue_status', 'route.from', 'route.to'])
            // Takings = the queue's PAID fares, summed in the same round trip
            // rather than one SUM query per trip. Aliased to `takings`.
            ->withSum(['bookings as takings' => fn ($q) => $q->where('paid', true)], 'amount')
            ->where('vehicle_id', $vehicle->id)
            ->orderByDesc('created_at');

        $filter = strtolower((string) $request->input('status'));
        if (in_array($filter, self::FILTERABLE, true)) {
            $query->whereHas('queue_status', fn ($q) => $q->where('status', self::STATUSES[$filter]));
        }

        $total = (clone $query)->count();
        $page = max((int) $request->input('page', 1), 1);

        $trips = $query->skip(($page - 1) * self::PER_PAGE)->take(self::PER_PAGE)->get()
            ->map(fn (Queue $queue) => [
                'id' => (int) $queue->id,
                'queue_number' => $queue->queue_number,
                'position' => $queue->position === null ? null : (int) $queue->position,
                'route' => [
                    'from' => optional(optional($queue->route)->from)->name,
                    'to' => optional(optional($queue->route)->to)->name,
                ],
                'status' => optional($queue->queue_status)->status,
                // Carbon::parse, not optional()->toIso8601String(): these columns
                // carry no model cast, so they read back from the DB as strings.
                'start_time' => $queue->start_time ? Carbon::parse($queue->start_time)->toIso8601String() : null,
                'end_time' => $queue->end_time ? Carbon::parse($queue->end_time)->toIso8601String() : null,
                'takings' => (float) ($queue->takings ?? 0),
            ]);

        return response()->json([
            'vehicle' => ['id' => (int) $vehicle->id, 'plate' => $vehicle->plate],
            'trips' => $trips,
            // So the app can render tabs without re-deriving the rules.
            'statuses' => array_values(self::STATUSES),
            'total' => $total,
            'per_page' => self::PER_PAGE,
            'current_page' => $page,
            'last_page' => (int) max(ceil($total / self::PER_PAGE), 1),
        ]);
    }
}
