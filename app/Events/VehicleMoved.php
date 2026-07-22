<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\VehicleLocation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pushed over Reverb every time a driver pings a new position. Passengers who
 * booked the trip (and its crew) are subscribed to `trip.{queueId}` and get the
 * moving dot in real time — no polling.
 */
class VehicleMoved implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public VehicleLocation $location,
        public ?string $plate = null,
    ) {}

    public function broadcastOn(): array
    {
        // No queue → the vehicle isn't on an active trip, so no one is listening.
        if ($this->location->queue_id === null) {
            return [];
        }

        return [new PrivateChannel('trip.' . $this->location->queue_id)];
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
