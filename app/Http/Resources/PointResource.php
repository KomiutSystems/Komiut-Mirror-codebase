<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contract-capture of the current Point JSON shape.
 *
 * Keys derived from the points table migration (2023_10_02_042944) plus the
 * redeemed alter (2024_06_13), id + timestamps.
 */
class PointResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'name' => $this->name,
            'phone' => $this->phone,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'points' => $this->points,
            'redeemed' => $this->redeemed,
            'sacco_id' => $this->sacco_id,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            'sacco' => $this->whenLoaded('sacco'),
            'user' => $this->whenLoaded('user'),
        ];
    }
}
