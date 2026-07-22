<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contract-capture of the current Sacco JSON shape.
 *
 * Keys derived from the saccos table migration (2023_07_08_100245), id +
 * timestamps. The model's $hidden secrets (paybill, passkey, consumer_key,
 * consumer_secret) are intentionally omitted to mirror serialization.
 */
class SaccoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slogan' => $this->slogan,
            'phone' => $this->phone,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            'mpesa_payment' => $this->whenLoaded('mpesa_payment'),
        ];
    }
}
