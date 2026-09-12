<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Models\Queue;
use App\Models\QueuePlace;
use App\Models\QueueStatus;
use App\Models\Route;
use App\Models\RouteStage;
use App\Models\SaccoTerminus;
use App\Models\Terminus;
use App\Models\Vehicle;
use App\Services\Fares\FareResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A bus that goes live on a route is on a trip -- this is that trip.
 *
 * THE RULE, decided 2026-09-12: a vehicle is available for booking when it is
 * LIVE (the driver's phone is broadcasting), not when it is in a queue. At the
 * main terminus passengers walk on and pay the conductor; the booking flow is
 * for the bus on the road, and the driver broadcasting is the offer. KDN 458N
 * was live on the Nairobi-Thika road, on the dashboard's map, and invisible in
 * the passenger app -- because its last queue had completed and the passenger
 * list was a list of queues.
 *
 * But a booking has to sit on something: `bookings.queue_id` is NOT NULL, the
 * tracker's channel is `trip.{queue_id}`, and the crew end a trip by its queue.
 * So going live on a route with no open queue creates one -- an ACTIVE run,
 * with no stage position and no place in any FIFO line, on the route's origin
 * terminus, owned by the driver. It is the trip the driver is already on, made
 * bookable. It ends the way every trip ends: driver/trip/end, with its paid
 * passengers marked; or, forgotten, by the stale-queue sweep, which settles them.
 *
 * Created by the DRIVER's go-live, never by a passenger's tap: the driver is
 * the one saying "I am running this route now". Idempotent -- a bus that is
 * already on a Pending or Active queue keeps it, whatever route it pinged.
 */
final class LiveRun
{
    public function __construct(private readonly FareResolver $fares) {}

    /**
     * The open trip for this bus, creating the run when it has none.
     *
     * @param  int  $driverId  who went live; recorded as the queue's owner
     */
    public function ensureFor(Vehicle $vehicle, int $routeId, int $driverId): ?Queue
    {
        $open = Queue::withoutGlobalScopes()
            ->where('vehicle_id', $vehicle->id)
            ->whereHas('queue_status', fn ($q) => $q->whereIn('status', ['Pending', 'Active']))
            ->latest('id')
            ->first();

        if ($open !== null) {
            return $open;
        }

        $route = Route::withoutGlobalScopes()->find($routeId);
        $active = QueueStatus::where('status', 'Active')->first();
        if ($route === null || $active === null) {
            return null;
        }

        return DB::transaction(function () use ($vehicle, $route, $active, $driverId) {
            $terminus = $this->originTerminus($route, $vehicle);

            $queue = new Queue;
            // No stage slot: this bus is on the road, not in a line. position
            // NULL keeps it out of the terminus FIFO and its unique index.
            $queue->position = null;
            $queue->queue_number = 'LIVE';
            $queue->vehicle_id = $vehicle->id;
            $queue->terminus_id = $terminus->id;
            $queue->queue_status_id = $active->id;
            $queue->route_id = $route->id;
            $queue->user_id = $driverId;
            $queue->amount = $this->fares->resolve((int) $vehicle->sacco_id, (int) $route->id, null, null) ?? 0;
            $queue->queue_type = false;
            $queue->start_time = Carbon::now();
            $queue->departed_at = Carbon::now();
            $queue->save();

            // The pick-up points along the route, as join() materialises them,
            // so the crew's last-stop pick-up works on a run like on a queue.
            foreach (RouteStage::where('route_id', $route->id)->pluck('id') as $stageId) {
                QueuePlace::firstOrCreate(['queue_id' => $queue->id, 'route_stage_id' => $stageId]);
            }

            Log::info('live run created', ['queue_id' => $queue->id, 'vehicle_id' => $vehicle->id, 'route_id' => $route->id]);

            return $queue;
        });
    }

    /**
     * The terminus at the route's origin: the SACCO's own if it has one there,
     * else any, else one named after the place -- a route's origin IS a place
     * buses start from, and refusing the run over a missing row would leave a
     * live bus unbookable for a dispatch table nobody filled in.
     */
    private function originTerminus(Route $route, Vehicle $vehicle): Terminus
    {
        $candidates = Terminus::where('place_id', $route->from_id)->orderBy('id')->get();

        if ($candidates->isNotEmpty() && $vehicle->sacco_id !== null) {
            $own = SaccoTerminus::where('sacco_id', $vehicle->sacco_id)
                ->whereIn('terminus_id', $candidates->pluck('id'))
                ->value('terminus_id');
            if ($own !== null) {
                return $candidates->firstWhere('id', (int) $own);
            }
        }

        if ($candidates->isNotEmpty()) {
            return $candidates->first();
        }

        $place = $route->from;
        Log::warning('live run: no terminus at route origin; creating one', ['route_id' => $route->id, 'place_id' => $route->from_id]);

        return Terminus::create([
            'name' => $place?->name ?? 'Route '.$route->id.' origin',
            'place_id' => $route->from_id,
            'latitude' => $place?->latitude,
            'longitude' => $place?->longitude,
            'status' => true,
        ]);
    }
}
