<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\VehicleLocation;
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
 * Every bus's fresh ping also goes to `bus.{vehicleId}`, where anyone who saved
 * that bus in the passenger app listens while it is open on their map -- with
 * or without a run, since a saved bus is a bus, not a trip. One event, one
 * broadcast, several channels; same payload on each, and nothing in it names a
 * passenger. See docs/school-bus-tracking.md ("Saved buses").
 */
class VehicleMoved implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Whether this fix is live, i.e. goes to `bus.{vehicleId}`. Decided HERE,
     * when the driver's ping dispatches the event, not in broadcastOn(): the
     * broadcast may run later on a queue worker, and "is this fix fresh" is a
     * question about the ping, not about when a worker gets to it.
     */
    public bool $fresh;

    public function __construct(
        public VehicleLocation $location,
        public ?string $plate = null,
    ) {
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

        if ($this->fresh) {
            $channels[] = new PrivateChannel('bus.'.$this->location->vehicle_id);
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
