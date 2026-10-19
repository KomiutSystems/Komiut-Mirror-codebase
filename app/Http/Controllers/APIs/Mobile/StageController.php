<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Mobile;

use App\Auth\Roles;
use App\Http\Controllers\Controller;
use App\Models\Queue;
use App\Models\SaccoTerminus;
use App\Models\Terminus;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * @group Mobile: stage manager
 *
 * The stage manager's screens in the mobile app (the Queue Supervisor role).
 *
 * A stage manager works ONE stage, picked in the app from their SACCO's stages,
 * and sees the buses that came through it today -- each bus's state in the
 * line and how much it made on its trip. Deliberately NOT the SACCO's total
 * collections: those are the SACCO's own business. Every number here is one
 * bus's.
 *
 * Trips are the crew's: a bus is at the stage when its crew joins the stage's
 * queue (or goes live on a route from it), and its trip runs from then until
 * the crew ends it. A trip's money is every payment to the bus inside that
 * window -- till payments included, which is nearly all of it -- not only
 * in-app bookings.
 */
class StageController extends Controller
{
    /** Roles that may use these screens. */
    private const ALLOWED = [Roles::QUEUE_SUPERVISOR, Roles::SACCO_ADMIN, Roles::OPERATIONS_MANAGER, Roles::SUPER_ADMIN];

    private const NAIROBI = 'Africa/Nairobi';

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * My stage, and the stages I can pick from
     *
     * @authenticated
     *
     * @response 200 {"stage": {"id": 57, "name": "Ambassadeur"}, "stages": [{"id": 57, "name": "Ambassadeur"}, {"id": 58, "name": "Nairobi CBD"}]}
     */
    public function show(Request $request): JsonResponse
    {
        if (($denied = $this->deny($request)) !== null) {
            return $denied;
        }

        $stages = $this->saccoStages($request->user());

        return response()->json([
            'stage' => $stages->firstWhere('id', (int) $request->user()->stage_terminus_id),
            'stages' => $stages->values(),
        ]);
    }

    /**
     * Pick my stage
     *
     * @authenticated
     *
     * @bodyParam terminus_id integer required One of my SACCO's stages, from GET mobile/stage. Example: 57
     */
    public function choose(Request $request): JsonResponse
    {
        if (($denied = $this->deny($request)) !== null) {
            return $denied;
        }

        $stage = $this->saccoStages($request->user())->firstWhere('id', (int) $request->input('terminus_id'));
        if ($stage === null) {
            return response()->json(['errors' => ['terminus_id' => ['Pick one of your SACCO\'s stages.']]], 422);
        }

        $request->user()->forceFill(['stage_terminus_id' => $stage['id']])->save();

        return response()->json(['stage' => $stage]);
    }

    /**
     * The buses at my stage today, and what each made on its trip
     *
     * Ordered: waiting in line (by position), then on a trip (most recent
     * first), then finished. `trip` is the bus's latest trip from this stage
     * today; `previous_trip` the one before it. `today_amount` is that one
     * bus's takings today, anywhere. Amounts update with every payment.
     *
     * @authenticated
     *
     * @response 200 {"stage": {"id": 57, "name": "Ambassadeur"}, "date": "2026-10-19", "as_of": "2026-10-19T09:30:00+03:00",
     *   "buses": [{"vehicle_id": 7, "plate": "KDA 123A", "state": "on_trip", "position": null, "today_amount": 4200,
     *     "trip": {"queue_id": 812, "kind": "stage", "status": "Active", "joined_at": "…", "departed_at": "…", "ended_at": null, "amount": 1350, "payments": 19},
     *     "previous_trip": {"queue_id": 790, "amount": 2850, "payments": 41, "...": "…"}}]}
     * @response 409 {"error": "Pick your stage first.", "reason": "no_stage"}
     */
    public function buses(Request $request): JsonResponse
    {
        if (($denied = $this->deny($request)) !== null) {
            return $denied;
        }

        $stage = $this->saccoStages($request->user())->firstWhere('id', (int) $request->user()->stage_terminus_id);
        if ($stage === null) {
            return response()->json(['error' => 'Pick your stage first.', 'reason' => 'no_stage'], 409);
        }

        $now = Carbon::now(self::NAIROBI);
        $dayStartUtc = $now->copy()->startOfDay()->setTimezone('UTC');

        // SaccoScope keeps it to the caller's SACCO: another SACCO's buses at
        // the same stage are not this stage manager's to see.
        $trips = Queue::with(['vehicle:id,plate', 'queue_status:id,status'])
            ->where('terminus_id', $stage['id'])
            ->where('created_at', '>=', $dayStartUtc)
            ->orderBy('id')
            ->get();

        $today = $trips->isEmpty() ? collect() : Transaction::withoutGlobalScopes()
            ->whereIn('vehicle_id', $trips->pluck('vehicle_id')->unique()->values())
            ->where('trans_date', '>=', $now->copy()->startOfDay()->format('Y-m-d H:i:s'))
            ->groupBy('vehicle_id')
            ->selectRaw('vehicle_id, SUM(amount) AS amount')
            ->pluck('amount', 'vehicle_id');

        $buses = $trips->groupBy('vehicle_id')->map(function (Collection $rows, $vehicleId) use ($today, $now) {
            $latest = $rows->last();
            $previous = $rows->count() > 1 ? $rows->slice(-2, 1)->first() : null;
            $status = optional($latest->queue_status)->status;
            $state = match (true) {
                $latest->end_time !== null || in_array($status, ['Completed', 'Cancelled'], true) => 'finished',
                $status === 'Active' => 'on_trip',
                default => 'waiting',
            };

            return [
                'vehicle_id' => (int) $vehicleId,
                'plate' => optional($latest->vehicle)->plate,
                'state' => $state,
                'position' => $state === 'waiting' && $latest->position !== null ? (int) $latest->position : null,
                'today_amount' => (float) ($today[$vehicleId] ?? 0),
                'trip' => $this->trip($latest, $now),
                'previous_trip' => $previous === null ? null : $this->trip($previous, $now),
            ];
        })->values();

        $order = ['waiting' => 0, 'on_trip' => 1, 'finished' => 2];
        $buses = $buses->sort(fn ($a, $b) => [$order[$a['state']], $a['position'] ?? PHP_INT_MAX, $b['trip']['joined_at'] ?? '']
            <=> [$order[$b['state']], $b['position'] ?? PHP_INT_MAX, $a['trip']['joined_at'] ?? ''])->values();

        return response()->json([
            'stage' => $stage,
            'date' => $now->toDateString(),
            'as_of' => $now->toIso8601String(),
            'buses' => $buses,
        ]);
    }

    /**
     * One trip and its money: every payment to the bus from the moment it
     * joined the line (passengers pay while it loads) or went live, until the
     * crew ended the trip -- or now, while it runs. Queue times are stored in
     * UTC; payment times (trans_date) are Nairobi wall clock, as Safaricom
     * reports them.
     *
     * @return array<string, mixed>
     */
    private function trip(Queue $q, Carbon $now): array
    {
        $start = Carbon::parse($q->start_time ?? $q->created_at, 'UTC')->setTimezone(self::NAIROBI);
        $end = $q->end_time !== null ? Carbon::parse($q->end_time, 'UTC')->setTimezone(self::NAIROBI) : $now->copy();

        $money = Transaction::withoutGlobalScopes()
            ->where('vehicle_id', $q->vehicle_id)
            ->whereBetween('trans_date', [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')])
            ->selectRaw('COALESCE(SUM(amount), 0) AS amount, COUNT(*) AS payments')
            ->first();

        return [
            'queue_id' => (int) $q->id,
            'kind' => $q->kind,
            'status' => optional($q->queue_status)->status,
            'joined_at' => $start->toIso8601String(),
            'departed_at' => $q->departed_at === null ? null : Carbon::parse($q->departed_at, 'UTC')->setTimezone(self::NAIROBI)->toIso8601String(),
            'ended_at' => $q->end_time === null ? null : $end->toIso8601String(),
            'amount' => (float) ($money->amount ?? 0),
            'payments' => (int) ($money->payments ?? 0),
        ];
    }

    /** @return Collection<int, array{id: int, name: string}> */
    private function saccoStages(User $user): Collection
    {
        $ids = SaccoTerminus::withoutGlobalScopes()
            ->where('sacco_id', (int) $user->sacco_id)
            ->where('status', true)
            ->pluck('terminus_id');

        return Terminus::with('place:id,name')->whereIn('id', $ids)->get(['id', 'name', 'place_id'])
            ->map(fn (Terminus $t) => ['id' => (int) $t->id, 'name' => $t->name ?: optional($t->place)->name])
            ->sortBy('name')->values();
    }

    private function deny(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if ($user->currentSaccoId() === null || ! $user->hasAnyRole(self::ALLOWED)) {
            return response()->json(['error' => 'These screens are for stage managers.'], 403);
        }

        return null;
    }
}
