<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Mobile;

use App\Auth\Roles;
use App\Http\Controllers\Controller;
use App\Models\Queue;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Sacco\SaccoStages;
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

        // No list to choose from: the stage is the SACCO's to assign. A null
        // stage means nobody has posted this manager yet, and the app says so
        // rather than offering a choice it is not theirs to make.
        return response()->json([
            'stage' => $this->saccoStages($request->user())
                ->firstWhere('id', (int) $request->user()->stage_terminus_id),
            'assigned_by_sacco' => true,
        ]);
    }

    /**
     * Retired: a manager does not pick their own stage
     *
     * Kept as a route so the shipped app gets an answer it can show instead of
     * a 404. Which stage you work decides whose takings you can see, so it is
     * an authorization change: the SACCO admin makes it from the crew screen
     * (POST crew/{id}/stage), where it is permission-gated and audited.
     *
     * @authenticated
     *
     * @response 403 {"error": "Your SACCO sets your stage. Ask your SACCO admin to post you to one."}
     */
    public function choose(Request $request): JsonResponse
    {
        return response()->json([
            'error' => 'Your SACCO sets your stage. Ask your SACCO admin to post you to one.',
        ], 403);
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

        // Today's line places and runs at this stage -- plus any still open
        // that began before midnight (a bus that joined at 23:50 is still on
        // its trip at 00:30). SaccoScope keeps it to the caller's SACCO:
        // another SACCO's buses at the same stage are not theirs to see.
        $rows = Queue::with(['vehicle:id,plate', 'queue_status:id,status'])
            ->where('terminus_id', $stage['id'])
            ->where(fn ($q) => $q->where('created_at', '>=', $dayStartUtc)
                ->orWhere(fn ($open) => $open->where('created_at', '>=', $now->copy()->subDay()->setTimezone('UTC'))
                    ->whereHas('queue_status', fn ($st) => $st->whereIn('status', ['Active', 'Pending']))))
            ->orderBy('id')
            ->get();

        $buses = $rows->groupBy('vehicle_id')->map(function (Collection $busRows, $vehicleId) use ($now, $dayStartUtc) {
            $trips = $this->trips($busRows);
            $latest = end($trips);
            $previous = count($trips) > 1 ? $trips[count($trips) - 2] : null;

            return [
                'vehicle_id' => (int) $vehicleId,
                'plate' => optional($busRows->last()->vehicle)->plate,
                'state' => $latest['state'],
                'position' => $latest['position'],
                'today_amount' => $this->money((int) $vehicleId, $dayStartUtc, null, $now)['amount'],
                'trip' => $this->present($latest, $now),
                'previous_trip' => $previous === null ? null : $this->present($previous, $now),
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
     * One bus's rows at this stage, as TRIPS: a place in the line and the run
     * the bus went live on when it left are one trip, not two -- rows whose
     * windows overlap are merged, so no payment is counted twice and the
     * money taken while loading belongs to the trip it loaded for.
     *
     * @param  Collection<int, Queue>  $rows
     * @return list<array{rows: list<Queue>, start: Carbon, end: ?Carbon, state: string, position: ?int}>
     */
    private function trips(Collection $rows): array
    {
        $trips = [];
        foreach ($rows->sortBy(fn (Queue $q) => $this->startOf($q)->getTimestamp())->values() as $q) {
            $start = $this->startOf($q);
            $end = $this->endOf($q);
            $last = count($trips) - 1;
            if ($last >= 0 && ($trips[$last]['end'] === null || $start->lte($trips[$last]['end']))) {
                $trips[$last]['rows'][] = $q;
                $trips[$last]['end'] = $end === null || $trips[$last]['end'] === null ? null : $end->max($trips[$last]['end']);
            } else {
                $trips[] = ['rows' => [$q], 'start' => $start, 'end' => $end];
            }
        }

        foreach ($trips as &$trip) {
            $q = end($trip['rows']);
            $status = optional($q->queue_status)->status;
            $trip['state'] = match (true) {
                $trip['end'] !== null => 'finished',
                $status === 'Active' => 'on_trip',
                default => 'waiting',
            };
            $trip['position'] = $trip['state'] === 'waiting' && $q->position !== null ? (int) $q->position : null;
        }
        unset($trip);

        return $trips;
    }

    /** When the trip began: joining the line (passengers pay while it loads) or going live. UTC. */
    private function startOf(Queue $q): Carbon
    {
        return Carbon::parse($q->start_time ?? $q->created_at, 'UTC');
    }

    /**
     * When it ended, UTC; null while it runs. A row closed without an end
     * time (the crew left the line) ended when it was closed, not "now".
     */
    private function endOf(Queue $q): ?Carbon
    {
        if ($q->end_time !== null) {
            return Carbon::parse($q->end_time, 'UTC');
        }

        return in_array(optional($q->queue_status)->status, ['Completed', 'Cancelled'], true)
            ? Carbon::parse($q->updated_at, 'UTC')
            : null;
    }

    /**
     * @param  array{rows: list<Queue>, start: Carbon, end: ?Carbon}  $trip
     * @return array<string, mixed>
     */
    private function present(array $trip, Carbon $now): array
    {
        $rows = collect($trip['rows']);
        $latest = $rows->last();
        $departed = $rows->pluck('departed_at')->filter()->first()
            ?? optional($rows->firstWhere('kind', Queue::KIND_LIVE))->start_time;
        $money = $this->money((int) $latest->vehicle_id, $trip['start'], $trip['end'], $now);

        return [
            'queue_id' => (int) $latest->id,
            'queue_ids' => $rows->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'kind' => $rows->contains('kind', Queue::KIND_LIVE) ? Queue::KIND_LIVE : Queue::KIND_STAGE,
            'status' => optional($latest->queue_status)->status,
            'joined_at' => $trip['start']->copy()->setTimezone(self::NAIROBI)->toIso8601String(),
            'departed_at' => $departed === null ? null : Carbon::parse($departed, 'UTC')->setTimezone(self::NAIROBI)->toIso8601String(),
            'ended_at' => $trip['end']?->copy()->setTimezone(self::NAIROBI)->toIso8601String(),
            'amount' => $money['amount'],
            'payments' => $money['payments'],
        ];
    }

    /**
     * Payments to one bus between two instants (UTC; null end = now).
     * M-Pesa payment times are Nairobi wall clock, as Safaricom reports them;
     * a cash fare the conductor confirms is stamped in UTC -- each is compared
     * on its own clock.
     *
     * @return array{amount: float, payments: int}
     */
    private function money(int $vehicleId, Carbon $fromUtc, ?Carbon $toUtc, Carbon $now): array
    {
        $toUtc ??= $now->copy()->setTimezone('UTC');
        $utc = [$fromUtc->copy()->setTimezone('UTC')->format('Y-m-d H:i:s'), $toUtc->copy()->setTimezone('UTC')->format('Y-m-d H:i:s')];
        $nairobi = [$fromUtc->copy()->setTimezone(self::NAIROBI)->format('Y-m-d H:i:s'), $toUtc->copy()->setTimezone(self::NAIROBI)->format('Y-m-d H:i:s')];

        $row = Transaction::withoutGlobalScopes()
            ->where('vehicle_id', $vehicleId)
            ->where(fn ($w) => $w->where(fn ($m) => $m->whereNull('cash_id')->whereBetween('trans_date', $nairobi))
                ->orWhere(fn ($c) => $c->whereNotNull('cash_id')->whereBetween('trans_date', $utc)))
            ->selectRaw('COALESCE(SUM(amount), 0) AS amount, COUNT(*) AS payments')
            ->first();

        return ['amount' => (float) ($row->amount ?? 0), 'payments' => (int) ($row->payments ?? 0)];
    }

    /** @return Collection<int, array{id: int, name: string}> */
    private function saccoStages(User $user): Collection
    {
        return app(SaccoStages::class)->forSacco((int) $user->sacco_id);
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
