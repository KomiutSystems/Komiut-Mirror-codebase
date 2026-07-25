<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The driver Bookings page shape (see komiut-v2 docs/driver_bookings_api_spec.md).
 *
 * Every field the app reads, named the way the app reads it: the passenger's
 * name/phone, the selected pickup/dropoff as point objects, and a bookingType
 * discriminator. `bookingType` is currently always "route" — pick-as-you-go is a
 * separate, not-yet-built booking kind, so passengerLatitude/Longitude are null
 * until that lands (the app already defaults these).
 */
class DriverBookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'bookingId' => $this->id,
            'passengerId' => $this->user_id,
            'passengerName' => $this->name,
            'passengerPhone' => $this->phone,
            'bookingType' => 'route',
            'seats' => $this->whenLoaded('seats', fn () => $this->seats->pluck('seat_id')->values()),
            'amount' => $this->amount,
            'status' => $this->paid ? 'PAID' : 'RESERVED',
            'boarded' => (bool) $this->boarded,
            'pickup' => $this->point($this->whenLoaded('from') ? $this->from : null),
            'dropoff' => $this->point($this->whenLoaded('to') ? $this->to : null),
            'passengerLatitude' => null,   // pick-as-you-go only (not built yet)
            'passengerLongitude' => null,
            'createdAt' => $this->created_at,
        ];
    }

    /** A place rendered as the app's PointDto, or null. */
    private function point($place): ?array
    {
        if ($place === null) {
            return null;
        }

        return [
            'id' => $place->id,
            'name' => $place->name,
            'latitude' => $place->latitude,
            'longitude' => $place->longitude,
        ];
    }
}
