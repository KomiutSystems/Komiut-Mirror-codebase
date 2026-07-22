<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contract-capture of the current Route JSON shape.
 *
 * Keys derived from the routes table migration (2023_07_10_145345), id +
 * timestamps.
 */
class RouteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'from_id' => $this->from_id,
            'to_id' => $this->to_id,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            'from' => $this->whenLoaded('from'),
            'to' => $this->whenLoaded('to'),
            'route_stages' => $this->whenLoaded('route_stages'),
            'queues' => $this->whenLoaded('queues'),
        ];
    }
}
