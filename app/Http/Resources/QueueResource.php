<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contract-capture of the current Queue JSON shape.
 *
 * Keys derived from the queues table migration (2023_07_19_082350), id +
 * timestamps.
 */
class QueueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'queue_number' => $this->queue_number,
            'position' => $this->position,
            'vehicle_id' => $this->vehicle_id,
            'terminus_id' => $this->terminus_id,
            'queue_status_id' => $this->queue_status_id,
            'route_id' => $this->route_id,
            'user_id' => $this->user_id,
            'amount' => $this->amount,
            'schedule_time' => $this->schedule_time,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'queue_type' => $this->queue_type,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            'vehicle' => $this->whenLoaded('vehicle'),
            'user' => $this->whenLoaded('user'),
            'terminus' => $this->whenLoaded('terminus'),
            'queue_status' => $this->whenLoaded('queue_status'),
            'route' => $this->whenLoaded('route'),
            'queue_places' => $this->whenLoaded('queue_places'),
            'bookings' => $this->whenLoaded('bookings'),
        ];
    }
}
