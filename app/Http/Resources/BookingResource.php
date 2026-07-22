<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contract-capture of the current Booking JSON shape.
 *
 * Keys derived from the bookings table migration (2023_07_22_131421), id +
 * timestamps. NOTE: the model's $fillable lists "stk_response", but no such
 * column exists in the migration, so it is intentionally omitted here.
 */
class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'passengers' => $this->passengers,
            'user_id' => $this->user_id,
            'queue_id' => $this->queue_id,
            'from_id' => $this->from_id,
            'to_id' => $this->to_id,
            'amount' => $this->amount,
            'boarded' => $this->boarded,
            'paid' => $this->paid,
            'start_time' => $this->start_time,
            'stop_time' => $this->stop_time,
            'created_by' => $this->created_by,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            'from' => $this->whenLoaded('from'),
            'to' => $this->whenLoaded('to'),
            'creator' => $this->whenLoaded('creator'),
            'user' => $this->whenLoaded('user'),
            'queue' => $this->whenLoaded('queue'),
            'seats' => $this->whenLoaded('seats'),
            'mpesa_booking_callbacks' => $this->whenLoaded('mpesa_booking_callbacks'),
        ];
    }
}
