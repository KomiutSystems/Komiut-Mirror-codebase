<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\VehicleLocation;
use App\Services\Buses\SavedBuses;
use App\Services\Location\VehicleLocationService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pushed over Reverb every time a driver pings a new position. Passengers who
 * booked the trip (and its crew) are subscribed to `trip.{queueId}` and get the
 * moving dot in real time — no polling.
 *
 * A bus's fresh ping also goes to `bus.{key}` (SavedBuses::key), where anyone
 * who saved that bus in the passenger app listens while it is open on their
 * map -- with or without a run, since a saved bus is a bus, not a trip -- but
 * only while someone subscribed to it lately (SavedBuses::isWatched): a ping
 * nobody can hear makes no call to Reverb. One event, one broadcast, several
 * channels; same payload on each, and nothing in it names a passenger. See
 * docs/school-bus-tracking.md ("Saved buses").
 */
class VehicleMoved implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Whether this fix is live, i.e. may go to `bus.{key}`. Decided HERE,
     * when the driver's ping dispatches the event, not in broadcastOn(): the
     * broadcast may run later on a queue worker, and "is this fix fresh" is a
     * question about the ping, not about when a worker gets to it.
     *
     * The default matters: the event is queued, and during a rolling deploy a
     * worker on this image unserializes jobs the previous image queued, which
     * carry no `fresh`. Unserializing sets only the properties the payload
     * names, so without a default it would be left uninitialized and
     * broadcastOn would throw.
     */
    public bool $fresh = false;

    /**
     * @deprecated Read by nothing. The previous release's broadcastOn() reads
     *             it (school-bus following) and declares it with no default,
     *             so it stays in the payload -- always false -- for one
     *             release, until no worker runs that image. Then delete it.
     *             Set in the constructor, NOT given a default: a property at
     *             its default is left out of the serialized payload.
     */
    public bool $toFollowers;

    public function __construct(
        public VehicleLocation $location,
        public ?string $plate = null,
    ) {
        $this->toFollowers = false;

        // Only a live fix reaches a saved-bus viewer, the same window
        // buses/live uses: a heartbeat re-sending a stale position is stored,
        // and shown to nobody as live.
        $this->fresh = $location->recorded_at !== null
            && $location->recorded_at->gte(now()->subSeconds(VehicleLocationService::FRESH_SECONDS));
    }

    public function broadcastOn(): array
    {
        $channels = [];

        // No queue → the vehicle isn't on an active trip, so no passenger is listening.
        if ($this->location->queue_id !== null) {
            $channels[] = new PrivateChannel('trip.'.$this->location->queue_id);
        }

        // Asked here, on the worker, not at dispatch: someone who opened the
        // bus between the ping and the broadcast still hears it.
        if ($this->fresh && SavedBuses::isWatched((int) $this->location->vehicle_id)) {
            $channels[] = new PrivateChannel('bus.'.SavedBuses::key((int) $this->location->vehicle_id));
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'vehicle.moved';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'vehicle_id' => $this->location->vehicle_id,
            'queue_id' => $this->location->queue_id,
            'plate' => $this->plate,
            'latitude' => $this->location->latitude,
            'longitude' => $this->location->longitude,
            'heading' => $this->location->heading,
            'recorded_at' => optional($this->location->recorded_at)->toIso8601String(),
        ];
    }
}
