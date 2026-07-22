<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contract-capture of the current RouteStage JSON shape.
 *
 * Keys derived from the route_stages table migration (2023_07_16_005434), id +
 * timestamps.
 */
class RouteStageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'route_id' => $this->route_id,
            'place_id' => $this->place_id,
            'longitude' => $this->longitude,
            'latitude' => $this->latitude,
            'distance' => $this->distance,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            'place' => $this->whenLoaded('place'),
            'route' => $this->whenLoaded('route'),
        ];
    }
}
