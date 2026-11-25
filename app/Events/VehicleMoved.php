<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\VehicleLocation;
use App\Services\Location\VehicleLocationService;
use App\Services\School\SchoolBuses;
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
 * A school bus's ping also goes to `school-bus.{vehicleId}`, where the parents
 * following it (and its crew) listen -- with or without a run, since a parent
 * follows the bus, not a trip. Same event, same payload: nothing in it names a
 * passenger, let alone a child. See docs/school-bus-tracking.md.
 */
class VehicleMoved implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Whether this ping goes to the bus's followers. Decided HERE, when the
     * driver's ping dispatches the event, not in broadcastOn(): the broadcast
     * may run later on a queue worker, and both "is it a school bus" and "is
     * this fix fresh" are questions about the ping, not about when a worker
     * gets to it. The school check is cached per vehicle (SchoolBuses), so a
     * matatu's ping costs no query for schools to exist.
     */
    public bool $toFollowers;

    public function __construct(
        public VehicleLocation $location,
        public ?string $plate = null,
    ) {
        // Only a live fix reaches a parent (docs rule 2): a heartbeat
        // re-sending a stale position is stored, and shown to nobody as live.
        $fresh = $location->recorded_at !== null
            && $location->recorded_at->gte(now()->subSeconds(VehicleLocationService::FRESH_SECONDS));

        $this->toFollowers = $fresh && SchoolBuses::isSchoolBus((int) $location->vehicle_id);
    }

    public function broadcastOn(): array
    {
        $channels = [];

        // No queue → the vehicle isn't on an active trip, so no passenger is listening.
        if ($this->location->queue_id !== null) {
            $channels[] = new PrivateChannel('trip.'.$this->location->queue_id);
        }

        if ($this->toFollowers) {
            $channels[] = new PrivateChannel('school-bus.'.$this->location->vehicle_id);
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
