<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Driver;

use App\Http\Controllers\Concerns\ResolvesDriverVehicle;
use App\Http\Controllers\Controller;
use App\Models\Queue;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;

/**
 * @group Driver
 *
 * The vehicles the signed-in person is attached to — one for a driver, a fleet
 * for an owner.
 *
 * WHY THIS EXISTS. Every other driver endpoint resolves "your vehicle" as the
 * most recent open assignment, which is correct for someone on shift: a driver
 * or conductor is on exactly one matatu today. An INVESTOR is attached the same
 * way, through vehicle_users, but to all of their buses at once — at NICCO ten
 * of them hold open assignments, one across 40 vehicles and another across 20.
 * They were being shown a single arbitrary bus with no way to reach the others,
 * and no indication the rest existed.
 *
 * This is the list the mobile app needs before it can ask about any of them.
 * With one entry a client can skip the picker entirely and behave exactly as it
 * does today; with several it has something to offer.
 *
 * SCOPING IS BY ASSIGNMENT, not permission and not SACCO. That distinction is
 * the whole point: an investor holding View Summaries can currently read their
 * SACCO's entire takings — 180 buses at NICCO — because nothing narrows to what
 * they actually own. Reading the same table the crew screens read gives them
 * their own buses and nobody else's.
 */
class FleetController extends Controller
{
    use ResolvesDriverVehicle;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * My vehicles
     *
     * Every vehicle the caller currently holds an open assignment on, with the
     * live trip on each where there is one. Ordered by plate.
     *
     * @authenticated
     *
     * @response 200 {"vehicles": [{"id": 151, "plate": "KCL 942M", "fleet_no": "12", "sacco": "NICCO MOVERS LIMITED", "on_trip": true, "queue_id": 44}], "count": 1}
     */
    public function index(): JsonResponse
    {
        $vehicles = $this->myVehicles();

        // One query for every live trip rather than one per bus: an owner with
        // 40 vehicles would otherwise pay 40 round trips to draw a list.
        $queues = Queue::whereIn('vehicle_id', $vehicles->pluck('id'))
            ->whereHas('queue_status', fn ($q) => $q->whereIn('status', ['Active', 'Pending']))
            ->orderByDesc('id')
            ->get()
            ->keyBy('vehicle_id');

        return response()->json([
            'vehicles' => $vehicles->map(function (Vehicle $v) use ($queues): array {
                $queue = $queues->get($v->id);

                return [
                    'id' => (int) $v->id,
                    'plate' => $v->plate,
                    'fleet_no' => $v->fleet_no,
                    'sacco' => $v->sacco?->name,
                    // What the owner actually wants to know at a glance: is this
                    // bus working right now.
                    'on_trip' => $queue !== null,
                    'queue_id' => $queue !== null ? (int) $queue->id : null,
                ];
            })->values(),
            'count' => $vehicles->count(),
        ]);
    }
}
